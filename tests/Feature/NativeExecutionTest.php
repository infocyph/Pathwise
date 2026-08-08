<?php

declare(strict_types=1);

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\FileManager\FileOperations;
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
        ->and($result->output)->toBeArray();
});

test('forced native file operations reject mounted paths', function () {
    FlysystemHelper::mount('native-mounted', new Filesystem(new LocalFilesystemAdapter($this->nativeRoot)));
    FlysystemHelper::write('native-mounted://source.txt', 'mounted');
    $operations = (new FileOperations('native-mounted://source.txt'))
        ->setExecutionStrategy(ExecutionStrategy::NATIVE);

    expect(fn () => $operations->copy($this->nativeRoot . DIRECTORY_SEPARATOR . 'copy.txt'))
        ->toThrow(UnsupportedStorageOperationException::class, 'local filesystem paths');
});
