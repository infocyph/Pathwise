<?php

declare(strict_types=1);

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\Native\NativeCommandRunner;
use Infocyph\Pathwise\Native\NativeExecutionFailure;
use Infocyph\Pathwise\Native\NativeExecutionLimits;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Results\NativeExecutionResult;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->nativeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise native Ω ', true);
    mkdir($this->nativeRoot, 0755, true);
});

afterEach(function () {
    FlysystemHelper::reset();
    if (is_dir($this->nativeRoot)) {
        (new Infocyph\Pathwise\DirectoryManager\DirectoryOperations($this->nativeRoot))->delete(true);
    }
});

test('forced native file copy handles spaces quotes Unicode and shell metacharacters', function () {
    $source = $this->nativeRoot . DIRECTORY_SEPARATOR . "source ' Ω ; \$.txt";
    $destination = $this->nativeRoot . DIRECTORY_SEPARATOR . "copied ' Ω ; \$.txt";
    file_put_contents($source, 'native-safe');

    $operations = new FileOperations($source);
    if (!NativeOperationsAdapter::canUseNativeFileCopy()) {
        expect(fn () => $operations->setExecutionStrategy(ExecutionStrategy::NATIVE)->copy($destination))
            ->toThrow(NativeExecutionException::class);

        return;
    }

    expect($operations->setExecutionStrategy(ExecutionStrategy::NATIVE)->copy($destination))->toBe($operations)
        ->and(file_get_contents($destination))->toBe('native-safe');
});

test('native adapters return typed execution results', function () {
    $source = $this->nativeRoot . DIRECTORY_SEPARATOR . 'source.txt';
    $destination = $this->nativeRoot . DIRECTORY_SEPARATOR . 'destination.txt';
    file_put_contents($source, 'result');

    $result = NativeOperationsAdapter::copyFile($source, $destination);

    expect($result)->toBeInstanceOf(NativeExecutionResult::class)
        ->and($result->exitCode)->toBeInt()
        ->and($result->output)->toBeArray()
        ->and($result->stdout)->toBeArray()
        ->and($result->stderr)->toBeArray();
});

test('native command runner captures stdout and stderr without shell execution', function () {
    $result = NativeCommandRunner::run([
        PHP_BINARY,
        '-r',
        'fwrite(STDOUT, "out\\n"); fwrite(STDERR, "err\\n");',
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->failure)->toBeNull()
        ->and($result->exitCode)->toBe(0)
        ->and($result->stdout)->toBe(['out'])
        ->and($result->stderr)->toBe(['err'])
        ->and($result->output)->toBe(['out', 'err']);
});

test('native command runner terminates commands that exceed the deadline', function () {
    $started = microtime(true);
    $result = NativeCommandRunner::run(
        [PHP_BINARY, '-r', 'usleep(5000000);'],
        limits: new NativeExecutionLimits(
            timeoutSeconds: 0.10,
            terminationGraceSeconds: 0.10,
            pollIntervalMicroseconds: 1_000,
        ),
    );
    $elapsed = microtime(true) - $started;

    expect($result->success)->toBeFalse()
        ->and($result->failure)->toBe(NativeExecutionFailure::TIMEOUT)
        ->and($result->exitCode)->toBe(124)
        ->and($elapsed)->toBeLessThan(2.0);
});

test('native command runner bounds stdout', function () {
    $result = NativeCommandRunner::run(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 8192));'],
        limits: new NativeExecutionLimits(
            stdoutBytes: 1_024,
            stderrBytes: 1_024,
            terminationGraceSeconds: 0.10,
            pollIntervalMicroseconds: 1_000,
        ),
    );

    expect($result->success)->toBeFalse()
        ->and($result->failure)->toBe(NativeExecutionFailure::STDOUT_LIMIT)
        ->and($result->exitCode)->toBe(125)
        ->and(strlen(implode("\n", $result->stdout)))->toBeLessThanOrEqual(1_024);
});

test('native command runner bounds stderr', function () {
    $result = NativeCommandRunner::run(
        [PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 8192));'],
        limits: new NativeExecutionLimits(
            stdoutBytes: 1_024,
            stderrBytes: 1_024,
            terminationGraceSeconds: 0.10,
            pollIntervalMicroseconds: 1_000,
        ),
    );

    expect($result->success)->toBeFalse()
        ->and($result->failure)->toBe(NativeExecutionFailure::STDERR_LIMIT)
        ->and($result->exitCode)->toBe(125)
        ->and(strlen(implode("\n", $result->stderr)))->toBeLessThanOrEqual(1_024);
});

test('native command runner drains stdout and stderr concurrently without deadlock', function () {
    $script = <<<'PHP'
for ($i = 0; $i < 32; $i++) {
    fwrite(STDOUT, str_repeat('o', 4096));
    fwrite(STDERR, str_repeat('e', 4096));
}
PHP;

    $result = NativeCommandRunner::run(
        [PHP_BINARY, '-r', $script],
        limits: new NativeExecutionLimits(
            stdoutBytes: 262_144,
            stderrBytes: 262_144,
            timeoutSeconds: 5.0,
            pollIntervalMicroseconds: 1_000,
        ),
    );

    expect($result->success)->toBeTrue()
        ->and($result->failure)->toBeNull()
        ->and(strlen(implode("\n", $result->stdout)))->toBe(131_072)
        ->and(strlen(implode("\n", $result->stderr)))->toBe(131_072);
});

test('native command runner exposes non-zero exit codes as typed failures', function () {
    $result = NativeCommandRunner::run([PHP_BINARY, '-r', 'exit(7);']);

    expect($result->success)->toBeFalse()
        ->and($result->failure)->toBe(NativeExecutionFailure::EXIT_CODE)
        ->and($result->exitCode)->toBe(7);
});

test('native execution limits reject invalid bounds', function () {
    expect(fn () => new NativeExecutionLimits(timeoutSeconds: 0.0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NativeExecutionLimits(stdoutBytes: 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NativeExecutionLimits(stderrBytes: 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NativeExecutionLimits(terminationGraceSeconds: 0.0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NativeExecutionLimits(pollIntervalMicroseconds: 0))
        ->toThrow(InvalidArgumentException::class);
});

test('forced native file operations reject mounted paths', function () {
    FlysystemHelper::mount('native-mounted', new Filesystem(new LocalFilesystemAdapter($this->nativeRoot)));
    FlysystemHelper::write('native-mounted://source.txt', 'mounted');
    $operations = (new FileOperations('native-mounted://source.txt'))
        ->setExecutionStrategy(ExecutionStrategy::NATIVE);

    expect(fn () => $operations->copy($this->nativeRoot . DIRECTORY_SEPARATOR . 'copy.txt'))
        ->toThrow(UnsupportedStorageOperationException::class, 'local filesystem paths');
});
