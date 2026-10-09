<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/FoundationHostLoad.php';

use Symfony\Component\Process\Process;

[$baseline, $candidate, $reportPath] = array_slice($argv, 1, 3);
$trials = ['4.1' => [], '4.2' => []];
foreach ([['4.1', '4.2'], ['4.2', '4.1'], ['4.1', '4.2']] as $order) {
    foreach ($order as $name) {
        $source = realpath($name === '4.1' ? $baseline : $candidate);
        $process = new Process(loadCommand('host', [PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/FoundationHostBenchmark.php', $source, '2000']));
        $process->mustRun();
        $data = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        if ($data['selected_source'] !== $source || $data['successes'] !== 2000) {
            throw new RuntimeException('Wrong revision or insufficient successful host requests.');
        }
        foreach ($data['loaded_sources'] as $entry) {
            if (!str_starts_with($entry['file'], $source . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Mixed source revisions in benchmark.');
            }
        }
        $trials[$name][] = $data;
        printf("%s / trial %d: %.0f RPM, p95=%.3fms, p99=%.3fms, RSS=%dkB\n", $name, count($trials[$name]), $data['rpm'], $data['p95_ms'], $data['p99_ms'], $data['peak_rss_kb']);
    }
}
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);

    return ($values[intdiv($count - 1, 2)] + $values[intdiv($count, 2)]) / 2;
}
$measure = static fn(string $key, string $name): float => median(array_column($trials[$name], $key));
$old = $measure('rpm', '4.1');
$new = $measure('rpm', '4.2');
$change = ($new / $old - 1) * 100;
$report = [
    'baseline_tag' => '4.1', 'candidate' => 'pull-request checkout',
    'description' => 'Matched synthetic in-process Foundation 3 filesystem responses; HTTP load is measured separately',
    'trial_count' => 3, 'trials' => $trials,
    'cpu_affinity' => loadAffinity(),
    'baseline_median_rpm' => $old, 'candidate_median_rpm' => $new,
    'rpm_change_percent' => $change, 'rpm_regression_limit_percent' => 2.0,
    'median_p95_ms' => array_combine(array_keys($trials), array_map(static fn($name) => $measure('p95_ms', $name), array_keys($trials))),
    'median_p99_ms' => array_combine(array_keys($trials), array_map(static fn($name) => $measure('p99_ms', $name), array_keys($trials))),
    'median_peak_rss_kb' => array_combine(array_keys($trials), array_map(static fn($name) => $measure('peak_rss_kb', $name), array_keys($trials))),
    'pass' => $change >= -2.0,
];
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
printf("Median RPM change: %+.2f%% (unchanged 2%% regression budget)\n", $change);
if (!$report['pass']) {
    throw new RuntimeException('Foundation host median RPM regression exceeds 2%.');
}
