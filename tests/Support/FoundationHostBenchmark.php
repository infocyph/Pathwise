<?php

declare(strict_types=1);

$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = $argv[1] ?? '';
$iterations = (int) ($argv[2] ?? 2000);
if (!is_dir($source) || $iterations < 200) {
    throw new RuntimeException('Benchmark expects an existing Pathwise source tree and at least 200 requests.');
}

// The exact same Foundation, Webrick and Composer dependency graph is used for both variants.
$loader->setPsr4('Infocyph\\Pathwise\\', rtrim($source, '/') . '/');

use Infocyph\Foundation\Filesystem\FilesystemResponseFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Webrick\Request\Request;

$root = sys_get_temp_dir() . '/pathwise-foundation-perf-' . bin2hex(random_bytes(6));
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
    ];
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR) . "\n");
} finally {
    unlink($root . '/public/assets/fixture.txt');
    rmdir($root . '/public/assets');
    rmdir($root . '/public');
    rmdir($root);
}
