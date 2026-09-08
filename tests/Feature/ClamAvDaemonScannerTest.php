<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\MalwareScannerException;
use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;
use Infocyph\Pathwise\StreamHandler\Scanner\ClamAvDaemonScanner;

/**
 * @return array{process: resource, pipes: array<int, resource>, script: string, endpoint: string}
 */
function startFakeClamd(string $response, int $responseDelayMicroseconds = 0): array
{
    $script = tempnam(sys_get_temp_dir(), 'pathwise_fake_clamd_');
    if ($script === false) {
        throw new RuntimeException('Unable to create fake clamd script.');
    }

    $code = <<<'PHP'
<?php

declare(strict_types=1);

function readExact($stream, int $length): string
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($stream, $length - strlen($buffer));
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Unexpected end of fake clamd request.');
        }
        $buffer .= $chunk;
    }

    return $buffer;
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if (!is_resource($server)) {
    fwrite(STDERR, "server-error:{$errorNumber}:{$errorMessage}\n");
    exit(2);
}

$address = stream_socket_get_name($server, false);
if (!is_string($address)) {
    exit(3);
}

echo $address, PHP_EOL;
flush();

$connection = stream_socket_accept($server, 5);
if (!is_resource($connection)) {
    exit(4);
}

try {
    if (readExact($connection, 10) !== "zINSTREAM\0") {
        exit(5);
    }

    while (true) {
        $header = readExact($connection, 4);
        $decoded = unpack('Nlength', $header);
        $length = is_array($decoded) ? ($decoded['length'] ?? null) : null;
        if (!is_int($length)) {
            exit(6);
        }
        if ($length === 0) {
            break;
        }
        readExact($connection, $length);
    }

    $delay = (int) ($argv[2] ?? 0);
    if ($delay > 0) {
        usleep($delay);
    }

    $response = base64_decode((string) ($argv[1] ?? ''), true);
    if (!is_string($response)) {
        exit(7);
    }

    fwrite($connection, $response);
} finally {
    fclose($connection);
    fclose($server);
}
PHP;

    file_put_contents($script, $code);

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $script, base64_encode($response), (string) $responseDelayMicroseconds],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process) || !isset($pipes[0], $pipes[1], $pipes[2])) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        unlink($script);

        throw new RuntimeException('Unable to start fake clamd process.');
    }

    fclose($pipes[0]);
    unset($pipes[0]);
    $endpoint = fgets($pipes[1]);
    if (!is_string($endpoint) || trim($endpoint) === '') {
        $stderr = stream_get_contents($pipes[2]);
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($script);

        throw new RuntimeException('Fake clamd failed to start: ' . (is_string($stderr) ? $stderr : ''));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'script' => $script,
        'endpoint' => 'tcp://' . trim($endpoint),
    ];
}

/** @param array{process: resource, pipes: array<int, resource>, script: string, endpoint: string} $server */
function stopFakeClamd(array $server): void
{
    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $status = proc_get_status($server['process']);
    if (is_array($status) && ($status['running'] ?? false)) {
        proc_terminate($server['process']);
    }
    proc_close($server['process']);

    if (is_file($server['script'])) {
        unlink($server['script']);
    }
}

function clamAvRequest(string $contents = 'scan-me'): MalwareScanRequest
{
    $path = tempnam(sys_get_temp_dir(), 'pathwise_clamd_input_');
    if ($path === false) {
        throw new RuntimeException('Unable to create ClamAV test input.');
    }
    file_put_contents($path, $contents);

    return new MalwareScanRequest($path, 'txt');
}

test('clamav daemon scanner accepts a clean INSTREAM response', function (): void {
    $server = startFakeClamd("stream: OK\0");
    $request = clamAvRequest();

    try {
        $scanner = new ClamAvDaemonScanner($server['endpoint']);

        expect($scanner->scan($request))->toBe(MalwareScanVerdict::CLEAN);
    } finally {
        if (is_file($request->localPath)) {
            unlink($request->localPath);
        }
        stopFakeClamd($server);
    }
});

test('clamav daemon scanner maps FOUND to malicious', function (): void {
    $server = startFakeClamd("stream: Eicar-Test-Signature FOUND\0");
    $request = clamAvRequest();

    try {
        $scanner = new ClamAvDaemonScanner($server['endpoint']);

        expect($scanner->scan($request))->toBe(MalwareScanVerdict::MALICIOUS);
    } finally {
        if (is_file($request->localPath)) {
            unlink($request->localPath);
        }
        stopFakeClamd($server);
    }
});

test('clamav daemon scanner fails closed on daemon error responses', function (): void {
    $server = startFakeClamd("stream: INSTREAM size limit exceeded. ERROR\0");
    $request = clamAvRequest();

    try {
        $scanner = new ClamAvDaemonScanner($server['endpoint']);

        expect(fn () => $scanner->scan($request))
            ->toThrow(MalwareScannerException::class, 'scan error or unsupported response');
    } finally {
        if (is_file($request->localPath)) {
            unlink($request->localPath);
        }
        stopFakeClamd($server);
    }
});

test('clamav daemon scanner fails closed on unsupported responses', function (): void {
    $server = startFakeClamd("stream: UNKNOWN\0");
    $request = clamAvRequest();

    try {
        $scanner = new ClamAvDaemonScanner($server['endpoint']);

        expect(fn () => $scanner->scan($request))
            ->toThrow(MalwareScannerException::class, 'scan error or unsupported response');
    } finally {
        if (is_file($request->localPath)) {
            unlink($request->localPath);
        }
        stopFakeClamd($server);
    }
});

test('clamav daemon scanner bounds response wait time', function (): void {
    $server = startFakeClamd("stream: OK\0", 200_000);
    $request = clamAvRequest();

    try {
        $scanner = new ClamAvDaemonScanner(
            endpoint: $server['endpoint'],
            ioTimeoutSeconds: 0.05,
        );

        expect(fn () => $scanner->scan($request))
            ->toThrow(MalwareScannerException::class, 'response timed out');
    } finally {
        if (is_file($request->localPath)) {
            unlink($request->localPath);
        }
        stopFakeClamd($server);
    }
});

test('clamav daemon scanner bounds response bytes', function (): void {
    $server = startFakeClamd(str_repeat('x', 256));
    $request = clamAvRequest();

    try {
        $scanner = new ClamAvDaemonScanner(
            endpoint: $server['endpoint'],
            maxResponseBytes: 128,
        );

        expect(fn () => $scanner->scan($request))
            ->toThrow(MalwareScannerException::class, 'response exceeds the configured limit');
    } finally {
        if (is_file($request->localPath)) {
            unlink($request->localPath);
        }
        stopFakeClamd($server);
    }
});

test('clamav daemon scanner rejects inputs above its stream limit before connecting', function (): void {
    $request = clamAvRequest('12345');

    try {
        $scanner = new ClamAvDaemonScanner(
            endpoint: 'tcp://127.0.0.1:9',
            maxStreamBytes: 4,
        );

        expect(fn () => $scanner->scan($request))
            ->toThrow(MalwareScannerException::class, 'scan input exceeds the configured stream limit');
    } finally {
        if (is_file($request->localPath)) {
            unlink($request->localPath);
        }
    }
});

test('clamav daemon scanner rejects remote TCP unless explicitly allowed', function (): void {
    expect(fn () => new ClamAvDaemonScanner('tcp://192.0.2.10:3310'))
        ->toThrow(InvalidArgumentException::class, 'allowRemoteTcp=true');
});
