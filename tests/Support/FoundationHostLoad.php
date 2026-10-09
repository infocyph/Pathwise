<?php

declare(strict_types=1);

// Test-only Linux host/load orchestration; Pathwise never starts these workers.
const HOST_WORKERS = 2;
const LOAD_BUDGETS = ['errors' => 0, 'p99_ms' => 1000, 'peak_rss_kb' => 524288,
    'rss_growth_kb' => 32768, 'fd_growth' => 32, 'rpm_regression_percent' => 2];

/** Match physical worker cores and keep load generation off their SMT siblings. */
function loadAffinity(): array
{
    static $affinity = null;
    if ($affinity !== null) {
        return $affinity;
    }
    preg_match('/^Cpus_allowed_list:\s+([0-9,-]+)$/m', file_get_contents('/proc/self/status'), $matches);
    $cores = [];
    foreach (explode(',', $matches[1]) as $range) {
        $bounds = array_map('intval', explode('-', $range));
        foreach (range($bounds[0], $bounds[1] ?? $bounds[0]) as $cpu) {
            $root = '/sys/devices/system/cpu/cpu' . $cpu . '/topology/';
            $key = trim(file_get_contents($root . 'physical_package_id')) . ':' . trim(file_get_contents($root . 'core_id'));
            $cores[$key][] = $cpu;
        }
    }
    $groups = array_values($cores);
    if (count($groups) < 3) {
        $all = array_merge(...$groups);

        return $affinity = ['host' => $all, 'clients' => $all, 'isolated_generator' => false];
    }

    return $affinity = ['host' => [$groups[0][0], $groups[1][0]],
        'clients' => array_merge(...array_slice($groups, 2)), 'isolated_generator' => true];
}

function loadCommand(string $role, array $command): array
{
    return array_merge(['taskset', '-c', implode(',', loadAffinity()[$role])], $command);
}

/** A process can disappear between samples; connection refusal is expected before readiness. */
function loadAttempt(callable $operation): mixed
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        return $operation();
    } catch (ErrorException) {
        return false;
    } finally {
        restore_error_handler();
    }
}

function loadMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);

    return ($values[intdiv($count - 1, 2)] + $values[intdiv($count, 2)]) / 2;
}

/** Live process tree, including all workers; never use pre-load RSS or lifetime HWM. */
function loadResources(int $pid): array
{
    $result = ['rss_kb' => 0, 'cpu_ticks' => 0, 'fds' => 0, 'pids' => [], 'at_ns' => hrtime(true)];
    $pending = [$pid];
    while ($pending !== []) {
        $current = array_pop($pending);
        $root = '/proc/' . $current;
        $status = loadAttempt(static fn() => file_get_contents($root . '/status'));
        $stat = loadAttempt(static fn() => file_get_contents($root . '/stat'));
        if (!is_string($status) || !is_string($stat) || !preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches)) {
            continue;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 1)));
        $result['rss_kb'] += (int) $matches[1];
        $result['cpu_ticks'] += (int) $fields[11] + (int) $fields[12];
        $result['fds'] += count(glob($root . '/fd/*') ?: []);
        $result['pids'][] = $current;
        $children = trim(loadAttempt(static fn() => file_get_contents($root . '/task/' . $current . '/children')) ?: '');
        if ($children !== '') {
            array_push($pending, ...array_map('intval', preg_split('/\s+/', $children)));
        }
    }

    return $result;
}

/** Fixed-length HTTP/1.1 test client. Unsupported framing fails closed. */
function loadRequest(mixed $socket, string $path, bool $range = false, ?bool &$close = null): array
{
    $request = "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n"
        . ($range ? "Range: bytes=4099-12288\r\n" : '') . "\r\n";
    $offset = 0;
    while ($offset < strlen($request)) {
        $bytes = fwrite($socket, substr($request, $offset));
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('HTTP request write failed.');
        }
        $offset += $bytes;
    }
    $status = fgets($socket);
    if (!is_string($status) || !preg_match('/^HTTP\/1\.1 (\d{3}) /', $status, $matches)) {
        throw new RuntimeException('HTTP response missing or timed out.');
    }
    $length = null;
    $close = false;
    while (($line = fgets($socket)) !== "\r\n") {
        if ($line === false) {
            throw new RuntimeException('Incomplete HTTP headers.');
        }
        if (str_starts_with(strtolower($line), 'content-length:')) {
            $length = (int) trim(substr($line, 15));
        }
        if (str_starts_with(strtolower($line), 'connection:') && str_contains(strtolower($line), 'close')) {
            $close = true;
        }
    }
    if ($length === null || $length < 0 || $length > 1048576) {
        throw new RuntimeException('Unexpected HTTP body framing.');
    }
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($socket, min(65536, $length - strlen($body)));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete HTTP body.');
        }
        $body .= $chunk;
    }

    return [(int) $matches[1], $body];
}

function loadConnect(int $port): mixed
{
    $socket = loadAttempt(static fn() => stream_socket_client('tcp://127.0.0.1:' . $port, timeout: 2));
    if (!is_resource($socket)) {
        throw new RuntimeException('HTTP host connection failed.');
    }
    stream_set_timeout($socket, 5);

    return $socket;
}

final class LoadHost
{
    public readonly string $source;
    public readonly int $port;
    public readonly int $pid;
    private mixed $process;
    private string $root;
    public array $workers = [];
    private bool $closed = false;

    public function __construct(string $source, bool $bound = false)
    {
        $this->source = realpath($source) ?: throw new RuntimeException('Missing selected source.');
        $this->root = sys_get_temp_dir() . '/pathwise-load-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
        fclose($reservation);
        $this->process = proc_open(loadCommand('host', [PHP_BINARY, '-d', 'opcache.enable_cli=1', __DIR__ . '/FoundationHostBenchmark.php',
            $this->source, '200', '--serve', '127.0.0.1:' . $this->port, (string) HOST_WORKERS,
            $bound ? 'bound' : 'unbound', $this->root . '/fixture']),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/host.log', 'a'], 2 => ['file', $this->root . '/host.log', 'a']], $pipes);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Unable to start owned HTTP test host.');
        }
        $this->pid = proc_get_status($this->process)['pid'];
    }

    public function ready(): array
    {
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            if (!proc_get_status($this->process)['running'] || hrtime(true) > $deadline) {
                throw new RuntimeException('Host not ready: ' . file_get_contents($this->root . '/host.log'));
            }
            try {
                $this->verifyWorkers();
            } catch (RuntimeException $exception) {
                if (count($this->workers) !== 0) {
                    throw $exception;
                }
                usleep(50000);
            }
        } while (count($this->workers) < HOST_WORKERS);

        return array_values($this->workers);
    }

    public function verifyWorkers(): void
    {
        // Simultaneous accepted connections distribute readiness probes across actual workers.
        $sockets = [];
        try {
            for ($i = 0; $i < 8; $i++) {
                $sockets[] = loadConnect($this->port);
            }
            foreach ($sockets as $socket) {
                [$status, $body] = loadRequest($socket, '/ready');
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if ($status !== 200 || $data['selected_source'] !== $this->source || $data['loaded_sources'] === []) {
                    throw new RuntimeException('Host loaded wrong or missing revision.');
                }
                foreach ($data['loaded_sources'] as $entry) {
                    if (!str_starts_with($entry['file'], $this->source . '/')) {
                        throw new RuntimeException('Mixed Pathwise revisions in HTTP host.');
                    }
                }
                $this->workers[$data['pid']] = $data;
            }
            if (count($this->workers) > HOST_WORKERS) {
                throw new RuntimeException('Unexpected worker replacement.');
            }
        } finally {
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        proc_terminate($this->process);
        $deadline = hrtime(true) + 10_000_000_000;
        while (proc_get_status($this->process)['running'] && hrtime(true) < $deadline) {
            usleep(50000);
        }
        if (proc_get_status($this->process)['running']) {
            foreach (loadResources($this->pid)['pids'] as $pid) {
                posix_kill($pid, SIGKILL);
            }
        }
        proc_close($this->process);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}

function loadClient(int $port, string $output, int $index): void
{
    $latencies = [];
    $error = null;
    $socket = null;
    try {
        $socket = loadConnect($port);
        $small = str_repeat('matched-foundation-request-', 32);
        $large = str_repeat('0123456789abcdef', 16384);
        $workload = [['/small', false, 200, $small], ['/large', false, 200, $large], ['/range', true, 206, substr($large, 4099, 8190)]];
        if (loadRequest($socket, '/small', close: $close) !== [200, $small]) {
            throw new RuntimeException('Invalid HTTP warmup.');
        }
        // Warm every payload/range path and OPcache before declaring this client ready.
        for ($warm = 0; $warm < 100; $warm++) {
            [$path, $range, $status, $body] = $workload[$warm % 3];
            if (loadRequest($socket, $path, $range, $close) !== [$status, $body]) {
                throw new RuntimeException('Invalid mixed-workload warmup.');
            }
        }
        fwrite(STDOUT, "ready\n");
        $stop = (int) trim(fgets(STDIN));
        $usage = getrusage();
        $cpuStart = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000;
        while (hrtime(true) < $stop) {
            [$path, $range, $status, $body] = $workload[$index++ % 3];
            $start = hrtime(true);
            if ($close) {
                fclose($socket);
                $socket = loadConnect($port);
            }
            if (loadRequest($socket, $path, $range, $close) !== [$status, $body]) {
                throw new RuntimeException('Incorrect HTTP status/body under load.');
            }
            $latencies[] = (hrtime(true) - $start) / 1_000_000;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        fwrite(STDOUT, "error\n");
    } finally {
        $usage = getrusage();
        $cpuSeconds = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000 - ($cpuStart ?? 0);
        if (is_resource($socket)) {
            fclose($socket);
        }
        file_put_contents($output, json_encode(['latencies' => $latencies, 'error' => $error, 'finished_ns' => hrtime(true), 'cpu_seconds' => $cpuSeconds], JSON_THROW_ON_ERROR));
    }
}

function loadPhase(LoadHost $host, int $concurrency, int $seconds): array
{
    $root = sys_get_temp_dir() . '/pathwise-clients-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $clients = [];
    $samples = [];
    try {
        for ($index = 0; $index < $concurrency; $index++) {
            $output = $root . '/' . $index . '.json';
            $process = proc_open(loadCommand('clients', [PHP_BINARY, __FILE__, '--client', (string) $host->port, $output, (string) $index]),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $root . '/client.log', 'a']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start HTTP load client.');
            }
            stream_set_timeout($pipes[1], 10);
            $clients[] = ['process' => $process, 'start' => $pipes[0], 'ready' => $pipes[1], 'output' => $output];
        }
        foreach ($clients as $client) {
            if (fgets($client['ready']) !== "ready\n") {
                throw new RuntimeException('HTTP clients failed warmup readiness.');
            }
        }
        $before = loadResources($host->pid);
        $start = hrtime(true);
        $stop = $start + $seconds * 1_000_000_000;
        foreach ($clients as $client) {
            fwrite($client['start'], $stop . "\n");
        }
        $pending = array_column($clients, 'process');
        do {
            $sample = loadResources($host->pid);
            if ($sample['rss_kb'] === 0 || array_diff(array_keys($host->workers), $sample['pids']) !== []) {
                throw new RuntimeException('Missing live host workers during resource measurement.');
            }
            $samples[] = $sample;
            foreach ($pending as $key => $process) {
                if (!proc_get_status($process)['running']) {
                    unset($pending[$key]);
                }
            }
            if ($pending !== []) {
                usleep(100000);
            }
        } while ($pending !== []);
        $latencies = [];
        $errors = [];
        $finished = $start;
        $clientCpu = 0;
        foreach ($clients as $client) {
            $data = json_decode(file_get_contents($client['output']), true, 512, JSON_THROW_ON_ERROR);
            array_push($latencies, ...$data['latencies']);
            $finished = max($finished, $data['finished_ns']);
            $clientCpu += $data['cpu_seconds'];
            if ($data['error'] !== null) {
                $errors[] = $data['error'];
            }
        }
        usleep(100000);
        $after = loadResources($host->pid);
        $host->verifyWorkers();
        if ($latencies === [] || $samples === []) {
            throw new RuntimeException('No successful measured HTTP traffic: ' . json_encode($errors));
        }
        sort($latencies, SORT_NUMERIC);
        $elapsed = ($finished - $start) / 1_000_000_000;
        $count = count($latencies);
        $percentile = static fn(float $value): float => $latencies[min($count - 1, (int) ceil($count * $value) - 1)];
        $ticks = (int) trim(shell_exec('getconf CLK_TCK'));
        $report = ['concurrency' => $concurrency, 'workers' => HOST_WORKERS, 'seconds' => $elapsed,
            'successes' => $count, 'rpm' => $count * 60 / $elapsed, 'rps' => $count / $elapsed,
            'p50_ms' => $percentile(.5), 'p95_ms' => $percentile(.95), 'p99_ms' => $percentile(.99),
            'errors' => $errors, 'inflight_after_client_drain' => 0,
            'peak_rss_kb' => max(array_column($samples, 'rss_kb')),
            'rss_growth_kb' => $after['rss_kb'] - $before['rss_kb'], 'fd_growth' => $after['fds'] - $before['fds'],
            'cpu_percent' => 100 * ($after['cpu_ticks'] - $before['cpu_ticks']) / $ticks / $elapsed,
            'load_generator_cpu_percent' => 100 * $clientCpu / $elapsed,
            'steady_rss_kb' => $after['rss_kb'],
            'live_process_tree_samples' => $samples];
        if ($errors !== []) {
            throw new RuntimeException('HTTP request errors: ' . json_encode($errors));
        }
        foreach (['p99_ms', 'peak_rss_kb', 'rss_growth_kb', 'fd_growth'] as $key) {
            if ($report[$key] > LOAD_BUDGETS[$key]) {
                throw new RuntimeException('HTTP load exceeded budget: ' . $key . '=' . $report[$key]);
            }
        }

        return $report;
    } finally {
        foreach ($clients as $client) {
            if (proc_get_status($client['process'])['running']) {
                proc_terminate($client['process'], SIGKILL);
            }
            fclose($client['start']);
            fclose($client['ready']);
            proc_close($client['process']);
            if (is_file($client['output'])) {
                unlink($client['output']);
            }
        }
        if (is_file($root . '/client.log')) {
            unlink($root . '/client.log');
        }
        rmdir($root);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}
if (($argv[1] ?? '') === '--client') {
    loadClient((int) $argv[2], $argv[3], (int) $argv[4]);

    return;
}
[$baseline, $candidate, $output] = array_slice($argv, 1, 3);
$hosts = [];
$report = ['description' => 'Real loopback HTTP, Foundation 3 / host-owned Runwire 2.1.1, mixed 832B/256KiB/range downloads',
    'environment' => ['php' => PHP_VERSION, 'os' => php_uname(), 'opcache_cli' => true,
        'warmup_requests_per_client' => 101, 'cpu_affinity' => loadAffinity()],
    'budgets' => LOAD_BUDGETS, 'readiness' => [], 'profiles' => [], 'pass' => true];
try {
    $hosts['4.1'] = new LoadHost($baseline);
    $hosts['4.2'] = new LoadHost($candidate);
    foreach ($hosts as $name => $host) {
        $report['readiness'][$name] = $host->ready();
    }
    foreach ([1, 8, 32, 64] as $concurrency) {
        $trials = ['4.1' => [], '4.2' => []];
        foreach ([['4.1', '4.2'], ['4.2', '4.1'], ['4.1', '4.2']] as $order) {
            foreach ($order as $name) {
                $trial = loadPhase($hosts[$name], $concurrency, 10);
                $trials[$name][] = $trial;
                printf("HTTP %s c%d: %.0f RPM, p99 %.1fms\n", $name, $concurrency, $trial['rpm'], $trial['p99_ms']);
            }
        }
        $old = loadMedian(array_column($trials['4.1'], 'rpm'));
        $new = loadMedian(array_column($trials['4.2'], 'rpm'));
        $change = ($new / $old - 1) * 100;
        $spread = [];
        foreach ($trials as $name => $values) {
            $rpms = array_column($values, 'rpm');
            $spread[$name] = (max($rpms) - min($rpms)) / loadMedian($rpms) * 100;
        }
        $report['profiles'][$concurrency] = ['trials' => $trials, 'rpm_change_percent' => $change, 'trial_range_percent_of_median' => $spread];
        $report['pass'] = $report['pass'] && $change >= -LOAD_BUDGETS['rpm_regression_percent'];
    }
    foreach ($hosts as $host) {
        $host->close();
    }
    $medians = [];
    foreach ($report['profiles'] as $concurrency => $profile) {
        $medians[$concurrency] = loadMedian(array_column($profile['trials']['4.2'], 'rpm'));
    }
    $peak = array_search(max($medians), $medians, true);
    $report['peak_sustained_profile'] = ['concurrency' => $peak, 'rpm' => $medians[$peak], 'rps' => $medians[$peak] / 60];
    $report['saturation_observed_in_tested_range'] = $medians[64] <= $medians[32] * 1.05;
    $hosts = ['bound' => new LoadHost($candidate, true)];
    $report['readiness']['bound'] = $hosts['bound']->ready();
    $report['bound_soak'] = loadPhase($hosts['bound'], 32, 30);
} catch (Throwable $exception) {
    $report['pass'] = false;
    $report['error'] = $exception->getMessage();
    throw $exception;
} finally {
    foreach ($hosts as $host) {
        $host->close();
    }
    file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}
if (!$report['pass']) {
    throw new RuntimeException('Matched HTTP median RPM regression exceeds unchanged 2% budget.');
}
