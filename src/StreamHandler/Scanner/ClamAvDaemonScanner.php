<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Scanner;

use Infocyph\Pathwise\Exceptions\MalwareScannerException;
use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;
use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;

/**
 * Scans Pathwise-owned local staging files through a running ClamAV clamd daemon.
 *
 * Files are streamed with the clamd INSTREAM protocol, so the daemon never needs
 * filesystem access to the original upload path. Unix sockets are preferred.
 * TCP is accepted for loopback endpoints by default; remote TCP must be opted in
 * explicitly because clamd does not authenticate or encrypt its TCP protocol.
 */
final readonly class ClamAvDaemonScanner implements MalwareScannerInterface
{
    public function __construct(
        private string $endpoint,
        private float $connectTimeoutSeconds = 2.0,
        private float $ioTimeoutSeconds = 30.0,
        private int $chunkSize = 65_536,
        private int $maxResponseBytes = 8_192,
        private bool $allowRemoteTcp = false,
    ) {
        $this->validateConfiguration();
    }

    public function scan(MalwareScanRequest $request): MalwareScanVerdict
    {
        $input = fopen($request->localPath, 'rb');
        if (!is_resource($input)) {
            throw new MalwareScannerException('Unable to open ClamAV scan input.');
        }

        $socket = $this->connect();

        try {
            $this->writeFully($socket, "zINSTREAM\0");

            while (!feof($input)) {
                $chunk = fread($input, $this->chunkSize);
                if (!is_string($chunk)) {
                    throw new MalwareScannerException('Unable to read ClamAV scan input.');
                }
                if ($chunk === '') {
                    if (feof($input)) {
                        break;
                    }

                    throw new MalwareScannerException('ClamAV scan input stalled.');
                }

                $this->writeFully($socket, pack('N', strlen($chunk)) . $chunk);
            }

            $this->writeFully($socket, pack('N', 0));

            return $this->parseResponse($this->readResponse($socket));
        } finally {
            fclose($input);
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect(): mixed
    {
        $errorNumber = 0;
        $errorMessage = '';
        set_error_handler(static fn(): bool => true);

        try {
            $socket = stream_socket_client(
                $this->endpoint,
                $errorNumber,
                $errorMessage,
                $this->connectTimeoutSeconds,
                STREAM_CLIENT_CONNECT,
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($socket)) {
            throw new MalwareScannerException(
                sprintf('Unable to connect to ClamAV daemon (%d).', $errorNumber),
            );
        }

        $seconds = (int) floor($this->ioTimeoutSeconds);
        $microseconds = (int) (($this->ioTimeoutSeconds - $seconds) * 1_000_000);
        if (!stream_set_timeout($socket, $seconds, $microseconds)) {
            fclose($socket);

            throw new MalwareScannerException('Unable to configure ClamAV socket timeout.');
        }

        return $socket;
    }

    private function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(trim($host, '[]'));

        return $normalized === 'localhost'
            || $normalized === '::1'
            || $normalized === '127.0.0.1'
            || str_starts_with($normalized, '127.');
    }

    private function parseResponse(string $response): MalwareScanVerdict
    {
        $response = trim($response, "\0\r\n \t");
        if ($response === '') {
            throw new MalwareScannerException('ClamAV returned an empty response.');
        }
        if (str_ends_with($response, ': OK')) {
            return MalwareScanVerdict::CLEAN;
        }
        if (str_ends_with($response, ' FOUND')) {
            return MalwareScanVerdict::MALICIOUS;
        }

        throw new MalwareScannerException('ClamAV returned a scan error or unsupported response.');
    }

    /** @param resource $socket */
    private function readResponse(mixed $socket): string
    {
        $response = '';

        while (strlen($response) < $this->maxResponseBytes) {
            $chunk = fread($socket, min(4_096, $this->maxResponseBytes - strlen($response)));
            if (!is_string($chunk)) {
                throw new MalwareScannerException('Unable to read ClamAV response.');
            }
            if ($chunk !== '') {
                $response .= $chunk;
                $terminator = strpos($response, "\0");
                if ($terminator !== false) {
                    return substr($response, 0, $terminator + 1);
                }

                continue;
            }

            $metadata = stream_get_meta_data($socket);
            if (($metadata['timed_out'] ?? false) === true) {
                throw new MalwareScannerException('ClamAV response timed out.');
            }
            if (feof($socket)) {
                return $response;
            }
        }

        throw new MalwareScannerException('ClamAV response exceeds the configured limit.');
    }

    private function validateConfiguration(): void
    {
        if ($this->endpoint === '' || str_contains($this->endpoint, "\0")) {
            throw new \InvalidArgumentException('ClamAV endpoint must not be empty.');
        }
        if ($this->connectTimeoutSeconds <= 0 || $this->ioTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('ClamAV timeouts must be positive.');
        }
        if ($this->chunkSize < 1 || $this->chunkSize > 1_048_576) {
            throw new \InvalidArgumentException('ClamAV chunk size must be between 1 byte and 1 MiB.');
        }
        if ($this->maxResponseBytes < 128 || $this->maxResponseBytes > 65_536) {
            throw new \InvalidArgumentException('ClamAV response limit must be between 128 bytes and 64 KiB.');
        }

        if (str_starts_with($this->endpoint, 'unix://')) {
            if (strlen($this->endpoint) <= strlen('unix://')) {
                throw new \InvalidArgumentException('ClamAV Unix socket path is required.');
            }

            return;
        }

        if (!str_starts_with($this->endpoint, 'tcp://')) {
            throw new \InvalidArgumentException('ClamAV endpoint must use unix:// or tcp://.');
        }

        $parts = parse_url($this->endpoint);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (!is_string($host) || $host === '' || !is_int($port) || $port < 1 || $port > 65_535) {
            throw new \InvalidArgumentException('ClamAV TCP endpoint must include a valid host and port.');
        }
        if (!$this->allowRemoteTcp && !$this->isLoopbackHost($host)) {
            throw new \InvalidArgumentException(
                'Remote ClamAV TCP endpoints require explicit allowRemoteTcp=true.',
            );
        }
    }

    /** @param resource $stream */
    private function writeFully(mixed $stream, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $written = fwrite($stream, substr($payload, $offset));
            if (!is_int($written) || $written < 1) {
                $metadata = stream_get_meta_data($stream);
                if (($metadata['timed_out'] ?? false) === true) {
                    throw new MalwareScannerException('ClamAV request timed out.');
                }

                throw new MalwareScannerException('Unable to write ClamAV request.');
            }

            $offset += $written;
        }
    }
}
