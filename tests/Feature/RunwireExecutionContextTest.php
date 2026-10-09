<?php

declare(strict_types=1);

use Infocyph\Pathwise\Integration\Runwire\RunwireExecutionContext;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
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

    expect(fn () => $processor->withRunwire($execution, function (DownloadProcessor $bound) use (
        $request,
        &$received,
    ): void {
        $preparation = $bound->prepareDownload($this->runwireFile);
        foreach ($bound->streamChunks($preparation) as $chunk) {
            $received[] = $chunk;
            $request->cancel(CancellationReason::HOST_CANCELLED);
        }
    }))->toThrow(CancelledException::class)
        ->and($received)->toBe(['abcd']);

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
