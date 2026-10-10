<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = $argv[1] ?? '';
$iterations = (int) ($argv[2] ?? 2000);
if (!is_dir($source) || $iterations < 200) {
    throw new RuntimeException('Benchmark expects an existing Pathwise source tree and at least 200 requests.');
}

$source = realpath($source);
if (!is_string($source)) {
    throw new RuntimeException('Unable to resolve benchmark source.');
}
// Prepend a strict source loader: an optimized Composer class map must never select the other revision.
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'Infocyph\\Pathwise\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $source . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (!is_file($file)) {
        throw new RuntimeException("Selected Pathwise revision has no class: {$class}");
    }
    require $file;
}, true, true);

/** @return array<string, array{file: string, sha256: string}> */
function benchmarkSources(string $source): array
{
    $result = [];
    foreach (array_merge(get_declared_classes(), get_declared_traits()) as $class) {
        if (!str_starts_with($class, 'Infocyph\\Pathwise\\')) {
            continue;
        }
        $file = (new ReflectionClass($class))->getFileName();
        if (!is_string($file) || !str_starts_with($file, $source . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Benchmark loaded Pathwise outside the selected revision: {$class}");
        }
        $sha256 = hash_file('sha256', $file);
        if (!is_string($sha256)) {
            throw new RuntimeException('Unable to fingerprint benchmark source.');
        }
        $result[$class] = ['file' => $file, 'sha256' => $sha256];
    }
    ksort($result);

    return $result;
}

if (($argv[3] ?? '') === '--verify-source') {
    class_exists(\Infocyph\Pathwise\StreamHandler\DownloadProcessor::class);
    class_exists(\Infocyph\Pathwise\StreamHandler\PublicFileResolver::class);
    fwrite(STDOUT, json_encode(benchmarkSources($source), JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

use Infocyph\Foundation\Filesystem\FilesystemResponseFactory;
use Infocyph\Foundation\Filesystem\FilesystemTransferFactory;
use Infocyph\Foundation\Filesystem\FilesystemPublicFileResolver;
use Infocyph\Foundation\Foundation;
use Infocyph\Pathwise\Integration\Runwire\RunwireExecutionContext;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Server;
use Infocyph\Webrick\Request\Request;

$root = ($argv[3] ?? '') === '--serve'
    ? ($argv[7] ?? throw new RuntimeException('HTTP benchmark requires an owned fixture root.'))
    : sys_get_temp_dir() . '/pathwise-foundation-perf-' . bin2hex(random_bytes(6));
mkdir($root . '/public/assets', 0700, true);
$content = str_repeat('matched-foundation-request-', 32);
file_put_contents($root . '/public/assets/fixture.txt', $content);

try {
    $app = Foundation::web([
        'base_path' => $root,
        '_config_cache' => false,
        'router' => ['cache' => false],
    ]);
    $app->boot();
    $responses = $app->make(FilesystemResponseFactory::class);
    if (($argv[3] ?? '') === '--serve') {
        file_put_contents($root . '/public/assets/large.bin', str_repeat('0123456789abcdef', 16_384));
        $download = $app->make(FilesystemTransferFactory::class)->download($root . '/public');
        $publicFiles = $app->make(FilesystemPublicFileResolver::class);
        class_exists(\Infocyph\Pathwise\StreamHandler\PublicFileResolver::class);
        $loadedSources = benchmarkSources($source);
        $bound = ($argv[6] ?? 'unbound') === 'bound';
        $handler = static function (HttpRequest $incoming, ResponseWriterInterface $writer) use ($download, $publicFiles, $source, $loadedSources, $bound): void {
            if ($incoming->target === '/ready') {
                $writer->end(json_encode(['pid' => getmypid(), 'selected_source' => $source, 'loaded_sources' => $loadedSources], JSON_THROW_ON_ERROR));

                return;
            }
            $relative = match ($incoming->target) {
                '/small' => 'assets/fixture.txt',
                '/large', '/range' => 'assets/large.bin',
                default => throw new RuntimeException('Unknown host benchmark workload.'),
            };
            $path = $publicFiles->resolve($relative)->path;
            $operation = static function (DownloadProcessor $owner) use ($incoming, $path, $writer): void {
                $prepared = $owner->prepareDownload($path, rangeHeader: $incoming->headers->first('range'));
                if (!$writer->start($prepared->status, Headers::fromArray($prepared->headers))->accepted()) {
                    throw new RuntimeException('HTTP host rejected response headers.');
                }
                foreach ($owner->streamChunks($prepared) as $chunk) {
                    if (!$writer->write($chunk)->accepted()) {
                        throw new RuntimeException('HTTP host rejected a bounded response chunk.');
                    }
                }
                if (!$writer->end()->accepted()) {
                    throw new RuntimeException('HTTP host rejected response completion.');
                }
            };
            if ($bound) {
                $download->withRunwire(new RunwireExecutionContext($incoming->context->runtime(), $incoming->context), $operation);
            } else {
                $operation($download);
            }
        };
        Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
            ->listen(Server::http($argv[4], $handler)->withWorkers((int) ($argv[5] ?? 2)))
            ->run();

        return;
    }
    $request = Request::fake(
        headers: ['Host' => 'localhost'],
        uri: 'http://localhost/assets/fixture.txt',
    );

    $sample = static function () use ($responses, $request, $content): float {
        $started = hrtime(true);
        $response = $responses->publicFile($request, 'assets/fixture.txt');
        $body = $response->getFileBody();
        if ($response->getStatusCode() !== 200 || $body === null || $body->read(strlen($content)) !== $content) {
            throw new RuntimeException('Incorrect Foundation filesystem response; benchmark aborted.');
        }

        return (hrtime(true) - $started) / 1_000_000;
    };
    for ($i = 0; $i < 200; $i++) {
        $sample();
    }
    $loadedSources = benchmarkSources($source);

    $latencies = [];
    $started = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $latencies[] = $sample();
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    sort($latencies, SORT_NUMERIC);
    $index = static fn(float $percent): int => min($iterations - 1, (int) ceil(($percent / 100) * $iterations) - 1);
    $memoryStatus = file_get_contents('/proc/self/status') ?: '';
    preg_match('/^VmHWM:\\s+(\\d+)\\s+kB/m', $memoryStatus, $rssMatches);
    $report = [
        'requests' => $iterations,
        'seconds' => $elapsed,
        'rpm' => 60.0 * $iterations / $elapsed,
        'median_ms' => $latencies[$index(50.0)],
        'p95_ms' => $latencies[$index(95.0)],
        'p99_ms' => $latencies[$index(99.0)],
        'peak_rss_kb' => isset($rssMatches[1]) ? (int) $rssMatches[1] : null,
        'successes' => $iterations,
        'host' => 'Foundation 3.0.1 + Webrick filesystem response (in-process, synthetic)',
        'selected_source' => $source,
        'loaded_sources' => $loadedSources,
    ];
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR) . "\n");
} finally {
    if (is_file($root . '/public/assets/large.bin')) {
        unlink($root . '/public/assets/large.bin');
    }
    unlink($root . '/public/assets/fixture.txt');
    rmdir($root . '/public/assets');
    rmdir($root . '/public');
    rmdir($root);
}
