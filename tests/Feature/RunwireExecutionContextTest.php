<?php

declare(strict_types=1);

use Infocyph\Pathwise\Integration\Runwire\RunwireExecutionContext;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

beforeEach(function (): void {
    $this->runwireRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_runwire_' . bin2hex(random_bytes(8));
    mkdir($this->runwireRoot, 0700);
    $this->runwireFile = $this->runwireRoot . DIRECTORY_SEPARATOR . 'download.txt';
    file_put_contents($this->runwireFile, 'abcdefghijklmnop');
});

afterEach(function (): void {
    if (is_file($this->runwireFile)) {
        unlink($this->runwireFile);
    }
    if (is_dir($this->runwireRoot)) {
        foreach (glob($this->runwireRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->runwireRoot);
    }
});

test('borrowed runtime and request are not completed or owned by Pathwise', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $execution = new RunwireExecutionContext($runtime, $request, checkpointEvery: 2);
    $processor = new DownloadProcessor();
    $processor->setChunkSize(4);

    $result = $processor->withRunwire(
        $execution,
        fn (DownloadProcessor $bound): string => implode('', iterator_to_array(
            $bound->streamChunks($bound->prepareDownload($this->runwireFile)),
            false,
        )),
    );
    expect($result)->toBe('abcdefghijklmnop')
        ->and($request->completed())->toBeFalse();

    $request->complete();
    expect(fn () => $processor->withRunwire($execution, static fn (): null => null))
        ->toThrow(LogicException::class, 'completed');
});

test('cancellation between yielded download chunks aborts before further reads', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $execution = new RunwireExecutionContext($runtime, $request);
    $processor = new DownloadProcessor();
    $processor->setChunkSize(4);
    $received = [];

    expect(function () use ($processor, $execution, $request, &$received): void {
        $processor->withRunwire($execution, function (DownloadProcessor $bound) use (
            $request,
            &$received,
        ): void {
            $preparation = $bound->prepareDownload($this->runwireFile);
            foreach ($bound->streamChunks($preparation) as $chunk) {
                $received[] = $chunk;
                $request->cancel(CancellationReason::HOST_CANCELLED);
            }
        });
    })->toThrow(CancelledException::class);
    expect($received)->toBe(['abcd']);

    // The owner retained no cancellation state after the scoped invocation.
    expect($processor->prepareDownload($this->runwireFile)->size)->toBe(16);
});

test('explicit Fiber forwarding is required, and nested bindings restore in finally', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $execution = new RunwireExecutionContext($runtime, $request);
    $processor = new DownloadProcessor();
    $other = new RunwireExecutionContext($runtime);

    expect(fn () => $processor->withRunwire($execution, function (DownloadProcessor $bound) use ($other): void {
        $bound->withRunwire($other, static fn (): null => null);
    }))->toThrow(LogicException::class, 'Nested Pathwise');

    $size = $processor->withRunwire($execution, function (DownloadProcessor $bound) use ($request): int {
        $fiber = new Fiber(function () use ($bound, $request): int {
            $request->cancel(CancellationReason::HOST_CANCELLED);

            // This Fiber did not explicitly bind the parent request.
            return $bound->prepareDownload($this->runwireFile)->size;
        });
        $fiber->start();

        return $fiber->getReturn();
    });
    expect($size)->toBe(16)
        ->and($processor->prepareDownload($this->runwireFile)->size)->toBe(16);
});

test('request-runtime mismatch and stale worker generations are rejected', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    expect(fn () => new RunwireExecutionContext(RuntimeContext::standalone(), $request))
        ->toThrow(LogicException::class, 'another runtime');

    $capabilities = new RuntimeCapabilities(RuntimeDriver::NATIVE);
    $newer = RuntimeContext::fromCapabilities($capabilities, 'worker', generation: 2);
    $older = RuntimeContext::fromCapabilities($capabilities, 'worker', generation: 1);
    $processor = new DownloadProcessor();
    $processor->withRunwire(new RunwireExecutionContext($newer), static fn (): null => null);

    expect(fn () => $processor->withRunwire(new RunwireExecutionContext($older), static fn (): null => null))
        ->toThrow(LogicException::class, 'Stale');
});

test('cancelled request prevents uploading before arbitrary mover is invoked', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $execution = new RunwireExecutionContext($runtime, $request);
    $uploader = new UploadProcessor();
    $uploader->setDirectorySettings($this->runwireRoot);
    $called = false;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$called): void {
            $called = true;
            file_put_contents($target, 'must-not-stage');
        },
        clientFilename: 'blocked.txt',
    );

    $request->cancel(CancellationReason::HOST_CANCELLED);
    expect(fn () => $uploader->withRunwire(
        $execution,
        static fn (UploadProcessor $bound): string => $bound->ingestSource($source),
    ))->toThrow(CancelledException::class)
        ->and($called)->toBeFalse();
});

test('borrowed checksum traversal checks host cancellation between files', function (): void {
    file_put_contents($this->runwireRoot . DIRECTORY_SEPARATOR . 'second.txt', 'second');
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $execution = new RunwireExecutionContext($runtime, $request, checkpointEvery: 1);
    $received = 0;

    expect(function () use ($execution, $request, &$received): void {
        foreach ($execution->iterateChecksums($this->runwireRoot) as $entry) {
            expect($entry['checksum'])->toHaveLength(64);
            $received++;
            $request->cancel(CancellationReason::HOST_CANCELLED);
        }
    })->toThrow(CancelledException::class);
    expect($received)->toBe(1);
});

test('real Runwire coroutine scopes support explicit intermediary forwarding and reject stale reuse', function (): void {
    $capabilities = new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true);
    $runtime = RuntimeContext::fromCapabilities($capabilities, 'host-test', generation: 3);
    $request = RequestContext::create($runtime);
    $download = new DownloadProcessor();
    $execution = null;
    $childResult = null;

    $forward = static function (
        RunwireExecutionContext $borrowed,
        DownloadProcessor $owner,
        string $path,
    ): int {
        return $owner->withRunwire(
            $borrowed,
            static fn (DownloadProcessor $bound): int => $bound->prepareDownload($path)->size,
        );
    };

    $result = (new CoroutineRuntime())->run(function (CoroutineScope $scope) use (
        $runtime,
        $request,
        $download,
        $forward,
        &$execution,
        &$childResult,
    ): int {
        $execution = new RunwireExecutionContext($runtime, $request, $scope, checkpointEvery: 1);

        $task = $scope->spawn(function () use (
            $execution,
            $download,
            $forward,
            &$childResult,
        ): void {
            $childResult = $forward($execution, $download, $this->runwireFile);
        });

        $size = $forward($execution, $download, $this->runwireFile);
        $task->await();

        return $size;
    });

    expect($result)->toBe(16)
        ->and($childResult)->toBe(16)
        ->and($request->completed())->toBeFalse();

    expect(fn () => $download->withRunwire(
        $execution,
        fn (DownloadProcessor $bound): int => $bound->prepareDownload($this->runwireFile)->size,
    ))->toThrow(LogicException::class);
});

test('archive cancellation rolls back published entries and removes owned staging', function (bool $directoryOwner): void {
    $archive = $this->runwireRoot . DIRECTORY_SEPARATOR . 'cancel.zip';
    $output = $this->runwireRoot . DIRECTORY_SEPARATOR . 'extracted';
    mkdir($output);
    file_put_contents($output . DIRECTORY_SEPARATOR . 'first.txt', 'original');
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('first.txt', 'changed');
    $zip->addFromString('second.txt', str_repeat('second-payload', 20_000));
    $zip->setCompressionName('second.txt', ZipArchive::CM_STORE);
    $zip->close();
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true),
        'archive-test',
    );
    $request = RequestContext::create($runtime);
    try {
        expect(function () use ($runtime, $request, $archive, $output, $directoryOwner): void {
            (new CoroutineRuntime())->run(function (CoroutineScope $scope) use ($runtime, $request, $archive, $output, $directoryOwner): void {
                $execution = new RunwireExecutionContext($runtime, $request, $scope, checkpointEvery: 1);
                $scope->spawn(static function () use ($scope, $request, $output): void {
                    while (file_get_contents($output . DIRECTORY_SEPARATOR . 'first.txt') !== 'changed') {
                        $scope->yieldNow();
                    }
                    $request->cancel(CancellationReason::HOST_CANCELLED);
                });
                if ($directoryOwner) {
                    (new \Infocyph\Pathwise\DirectoryManager\DirectoryOperations($output))->withRunwire(
                        $execution,
                        static fn($owner) => $owner->unzip($archive),
                    );
                } else {
                    (new \Infocyph\Pathwise\FileManager\FileCompression($archive))->withRunwire(
                        $execution,
                        static fn($owner) => $owner->decompress($output),
                    );
                }
            });
        })->toThrow(CancelledException::class);
        expect(file_get_contents($output . DIRECTORY_SEPARATOR . 'first.txt'))->toBe('original')
            ->and(is_file($output . DIRECTORY_SEPARATOR . 'second.txt'))->toBeFalse()
            ->and(glob($output . DIRECTORY_SEPARATOR . '.pathwise-zip-*'))->toBe([]);
    } finally {
        (new \Infocyph\Pathwise\DirectoryManager\DirectoryOperations($output))->delete(true);
    }
})->with([false, true]);

test('writer contention waits allow the host coroutine to release its lock', function (): void {
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true),
        'lock-test',
    );
    $request = RequestContext::create($runtime);
    $holder = fopen($this->runwireFile, 'c+');
    flock($holder, LOCK_EX);
    $writer = new \Infocyph\Pathwise\FileManager\SafeFileWriter($this->runwireFile);
    try {
        (new CoroutineRuntime())->run(function (CoroutineScope $scope) use ($runtime, $request, $holder, $writer): void {
            $scope->spawn(static function () use ($scope, $holder): void {
                $scope->sleep(0.01);
                flock($holder, LOCK_UN);
            });
            $writer->withRunwire(
                new RunwireExecutionContext($runtime, $request, $scope),
                static fn($owner) => $owner->lock(LOCK_EX, true, 10, 10),
            );
            $writer->writeBinary('cooperative');
            $writer->close();
        });
        expect(file_get_contents($this->runwireFile))->toBe('cooperative');
    } finally {
        $writer->close();
        flock($holder, LOCK_UN);
        fclose($holder);
    }
});

test('watcher intervals permit host progress and propagate request cancellation', function (): void {
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true),
        'watch-test',
    );
    $request = RequestContext::create($runtime);
    $observed = false;
    expect(function () use ($runtime, $request, &$observed): void {
        (new CoroutineRuntime())->run(function (CoroutineScope $scope) use ($runtime, $request, &$observed): void {
            $scope->spawn(function () use ($scope): void {
                $scope->sleep(0.01);
                file_put_contents($this->runwireRoot . DIRECTORY_SEPARATOR . 'created.txt', 'created');
            });
            \Infocyph\Pathwise\PathwiseFacade::watch(
                $this->runwireRoot,
                static function ($diff) use ($request, &$observed): void {
                    $observed = $diff->created !== [];
                    $request->cancel(CancellationReason::HOST_CANCELLED);
                },
                durationSeconds: 1,
                intervalMilliseconds: 10,
                execution: new RunwireExecutionContext($runtime, $request, $scope),
            );
        });
    })->toThrow(CancelledException::class);
    expect($observed)->toBeTrue();
});

test('benchmark revision selection overrides optimized Composer maps and rejects mixed sources', function (): void {
    $root = $this->runwireRoot . DIRECTORY_SEPARATOR . 'selected';
    $directory = $root . DIRECTORY_SEPARATOR . 'StreamHandler';
    mkdir($directory, 0700, true);
    foreach (['DownloadProcessor', 'PublicFileResolver'] as $class) {
        file_put_contents($directory . DIRECTORY_SEPARATOR . $class . '.php',
            '<?php namespace Infocyph\\Pathwise\\StreamHandler; final class ' . $class . ' {}');
    }
    $command = [PHP_BINARY, dirname(__DIR__) . '/Support/FoundationHostBenchmark.php', $root, '200', '--verify-source'];
    try {
        $process = new \Symfony\Component\Process\Process($command);
        $process->mustRun();
        $loaded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        expect($loaded)->toHaveCount(2);
        foreach ($loaded as $entry) {
            expect(str_starts_with($entry['file'], realpath($root) . DIRECTORY_SEPARATOR))->toBeTrue();
        }
        unlink($directory . DIRECTORY_SEPARATOR . 'PublicFileResolver.php');
        $process = new \Symfony\Component\Process\Process($command);
        $process->run();
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Selected Pathwise revision has no class');
    } finally {
        (new \Infocyph\Pathwise\DirectoryManager\DirectoryOperations($root))->delete(true);
    }
});

test('HTTP load clients discard an expired warmup connection before measurement', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $output = $this->runwireRoot . DIRECTORY_SEPARATOR . 'http-client.json';
    $process = proc_open([
        PHP_BINARY, dirname(__DIR__) . '/Support/FoundationHostLoad.php', '--client', $port, $output, '0',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $output . '.log', 'a']], $pipes);
    $connection = null;
    $respond = static function ($socket, bool $expire = false): bool {
        $line = fgets($socket);
        if ($line === false) {
            return false;
        }
        $path = explode(' ', $line)[1];
        while (($header = fgets($socket)) !== "\r\n") {
            if ($header === false) {
                throw new RuntimeException('Synthetic HTTP request headers were incomplete.');
            }
        }
        $large = str_repeat('0123456789abcdef', 16384);
        $body = match ($path) {
            '/small' => str_repeat('matched-foundation-request-', 32),
            '/large' => $large,
            '/range' => substr($large, 4099, 8190),
        };
        $status = $path === '/range' ? 206 : 200;
        $response = "HTTP/1.1 {$status} OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        if ($expire) {
            $response .= "HTTP/1.1 408 Request Timeout\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        }
        $offset = 0;
        while ($offset < strlen($response)) {
            $written = fwrite($socket, substr($response, $offset));
            if (!is_int($written) || $written === 0) {
                throw new RuntimeException('Synthetic HTTP response write failed.');
            }
            $offset += $written;
        }

        return true;
    };
    try {
        $connection = stream_socket_accept($server, 5);
        stream_set_timeout($connection, 5);
        for ($warm = 0; $warm < 101; $warm++) {
            expect($respond($connection, $warm === 100))->toBeTrue();
        }
        stream_set_timeout($pipes[1], 5);
        expect(trim(fgets($pipes[1])))->toBe('ready');
        fclose($connection);
        $connection = null;
        fwrite($pipes[0], (hrtime(true) + 200_000_000) . "\n");
        fclose($pipes[0]);
        $connection = stream_socket_accept($server, 5);
        expect($connection)->toBeResource();
        stream_set_timeout($connection, 5);
        $measured = 0;
        while ($respond($connection)) {
            $measured++;
        }
        expect(proc_close($process))->toBe(0);
        $result = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        expect($result['error'])->toBeNull()->and($result['latencies'])->not->toBeEmpty()->toHaveCount($measured);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($connection)) {
            fclose($connection);
        }
        fclose($server);
    }
});

test('borrowed waits honor a shorter request deadline with and without coroutine capability', function (bool $cooperative): void {
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: $cooperative),
        'deadline-wait',
    );
    $request = RequestContext::create($runtime, new \Infocyph\Runwire\Runtime\RequestExecutionPolicy(maxExecutionSeconds: 0.02));
    $started = hrtime(true);
    expect(function () use ($runtime, $request, $cooperative): void {
        if ($cooperative) {
            (new CoroutineRuntime())->run(static function (CoroutineScope $scope) use ($runtime, $request): void {
                (new RunwireExecutionContext($runtime, $request, $scope))->sleep(1);
            });
        } else {
            (new RunwireExecutionContext($runtime, $request))->sleep(1);
        }
    })->toThrow(CancelledException::class);
    expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(0.5)
        ->and($request->completed())->toBeFalse();
})->with([false, true]);

test('cancellation of a cooperative lock wait preserves the existing file and host scope', function (): void {
    $runtime = RuntimeContext::fromCapabilities(new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true), 'cancel-lock');
    $request = RequestContext::create($runtime);
    $holder = fopen($this->runwireFile, 'c+');
    flock($holder, LOCK_EX);
    $writer = new \Infocyph\Pathwise\FileManager\SafeFileWriter($this->runwireFile);
    try {
        (new CoroutineRuntime())->run(function (CoroutineScope $scope) use ($runtime, $request, $writer): void {
            $scope->spawn(static function () use ($scope, $request): void {
                $scope->sleep(0.01);
                $request->cancel(CancellationReason::HOST_CANCELLED);
            });
            expect(fn() => $writer->withRunwire(new RunwireExecutionContext($runtime, $request, $scope),
                static fn($owner) => $owner->lock(LOCK_EX, true, 5, 1000)))->toThrow(CancelledException::class);
            expect($scope->cancellation()->isCancelled())->toBeFalse();
        });
        flock($holder, LOCK_UN);
        expect(file_get_contents($this->runwireFile))->toBe('abcdefghijklmnop');
    } finally {
        $writer->close();
        flock($holder, LOCK_UN);
        fclose($holder);
    }
});

test('cancelled atomic lock acquisition discards uninitialized staging on close', function (): void {
    $runtime = RuntimeContext::fromCapabilities(new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true), 'atomic-lock');
    $request = RequestContext::create($runtime);
    $writer = (new \Infocyph\Pathwise\FileManager\SafeFileWriter($this->runwireFile))->enableAtomicWrite();
    try {
        expect(function () use ($runtime, $request, $writer): void {
            (new CoroutineRuntime())->run(function (CoroutineScope $scope) use ($runtime, $request, $writer): void {
                $scope->spawn(function () use ($scope, $request): void {
                    $end = hrtime(true) + 1_000_000_000;
                    while (count(glob($this->runwireRoot . DIRECTORY_SEPARATOR . '*') ?: []) < 2 && hrtime(true) < $end) {
                        $scope->yieldNow();
                    }
                    $request->cancel(CancellationReason::HOST_CANCELLED);
                });
                $writer->withRunwire(new RunwireExecutionContext($runtime, $request, $scope, checkpointEvery: 1),
                    static fn($owner) => $owner->lock());
            });
        })->toThrow(CancelledException::class);
    } finally {
        $writer->close();
    }
    expect(file_get_contents($this->runwireFile))->toBe('abcdefghijklmnop')
        ->and(scandir($this->runwireRoot))->toBe(['.', '..', 'download.txt']);
});

test('a committed extraction remains successful when its host request is cancelled afterwards', function (): void {
    $archive = $this->runwireRoot . DIRECTORY_SEPARATOR . 'committed.zip';
    $output = $this->runwireRoot . DIRECTORY_SEPARATOR . 'committed';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('done.txt', 'committed');
    $zip->close();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    try {
        $result = (new \Infocyph\Pathwise\FileManager\FileCompression($archive))->withRunwire(
            new RunwireExecutionContext($runtime, $request),
            static function ($owner) use ($request, $output): bool {
                $owner->decompress($output);
                $request->cancel(CancellationReason::HOST_CANCELLED);

                return true;
            },
        );
        expect($result)->toBeTrue()->and(file_get_contents($output . DIRECTORY_SEPARATOR . 'done.txt'))->toBe('committed');
    } finally {
        (new \Infocyph\Pathwise\DirectoryManager\DirectoryOperations($output))->delete(true);
    }
});

test('cancellation interrupts a partially copied ZIP entry before publication', function (): void {
    $archive = $this->runwireRoot . DIRECTORY_SEPARATOR . 'copy.zip';
    $output = $this->runwireRoot . DIRECTORY_SEPARATOR . 'copy';
    mkdir($output);
    file_put_contents($output . DIRECTORY_SEPARATOR . 'large.bin', 'original');
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('large.bin', random_bytes(1_048_576));
    $zip->setCompressionName('large.bin', ZipArchive::CM_STORE);
    $zip->close();
    $runtime = RuntimeContext::fromCapabilities(new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true), 'zip-copy');
    $request = RequestContext::create($runtime);
    $copied = false;
    try {
        expect(function () use ($runtime, $request, $archive, $output, &$copied): void {
            (new CoroutineRuntime())->run(function (CoroutineScope $scope) use ($runtime, $request, $archive, $output, &$copied): void {
                $scope->spawn(static function () use ($scope, $request, $output, &$copied): void {
                    while (true) {
                        foreach (glob($output . DIRECTORY_SEPARATOR . '.pathwise-zip-*.tmp') ?: [] as $temporary) {
                            clearstatcache(true, $temporary);
                            if (filesize($temporary) > 0) {
                                $copied = true;
                                $request->cancel(CancellationReason::HOST_CANCELLED);

                                return;
                            }
                        }
                        $scope->yieldNow();
                    }
                });
                (new \Infocyph\Pathwise\FileManager\FileCompression($archive))->withRunwire(
                    new RunwireExecutionContext($runtime, $request, $scope, checkpointEvery: 1),
                    static fn($owner) => $owner->decompress($output),
                );
            });
        })->toThrow(CancelledException::class);
        expect($copied)->toBeTrue()
            ->and(file_get_contents($output . DIRECTORY_SEPARATOR . 'large.bin'))->toBe('original')
            ->and(glob($output . DIRECTORY_SEPARATOR . '.pathwise-zip-*'))->toBe([]);
    } finally {
        (new \Infocyph\Pathwise\DirectoryManager\DirectoryOperations($output))->delete(true);
    }
});
