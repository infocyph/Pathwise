<?php

declare(strict_types=1);

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

beforeEach(function () {
    FlysystemHelper::reset();
    $this->adapterRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_adapter_contract_', true);
    mkdir($this->adapterRoot, 0755, true);
});

afterEach(function () {
    FlysystemHelper::reset();
    if (is_dir($this->adapterRoot)) {
        (new DirectoryOperations($this->adapterRoot))->delete(true);
    }
});

test('storage-neutral contracts run against the in-memory adapter', function () {
    $adapterClass = StorageFactory::officialDrivers()['inmemory']['adapter_class'];
    if (!class_exists($adapterClass)) {
        $this->markTestSkipped('Install league/flysystem-memory to run this adapter contract.');
    }

    StorageFactory::mount('memory-contract', ['driver' => 'inmemory']);
    $file = new FileOperations('memory-contract://source/file.txt');
    $file->create('memory')->copy('memory-contract://source/copy.txt');
    $report = (new DirectoryOperations('memory-contract://source'))->syncTo('memory-contract://target');

    expect($file->read())->toBe('memory')
        ->and(FlysystemHelper::read('memory-contract://source/copy.txt'))->toBe('memory')
        ->and($report->created)->toContain('file.txt', 'copy.txt');
});

test('read-only adapters preserve reads and reject mutations', function () {
    $adapterClass = StorageFactory::officialDrivers()['read-only']['adapter_class'];
    if (!class_exists($adapterClass)) {
        $this->markTestSkipped('Install league/flysystem-read-only to run this adapter contract.');
    }

    $localAdapter = new LocalFilesystemAdapter($this->adapterRoot);
    (new Filesystem($localAdapter))->write('readable.txt', 'read-only');
    StorageFactory::mount('read-only-contract', [
        'driver' => 'read-only',
        'constructor' => [$localAdapter],
    ]);
    $file = new FileOperations('read-only-contract://readable.txt');

    expect($file->read())->toBe('read-only')
        ->and(fn () => $file->create('blocked'))->toThrow(UnableToWriteFile::class)
        ->and(fn () => $file->getMetadata())->toThrow(UnsupportedStorageOperationException::class);
});

test('path-prefixing adapters confine storage-neutral writes to their prefix', function () {
    $adapterClass = StorageFactory::officialDrivers()['path-prefixing']['adapter_class'];
    if (!class_exists($adapterClass)) {
        $this->markTestSkipped('Install league/flysystem-path-prefixing to run this adapter contract.');
    }

    StorageFactory::mount('prefix-contract', [
        'driver' => 'path-prefixing',
        'constructor' => [new LocalFilesystemAdapter($this->adapterRoot), 'tenant-a'],
    ]);
    $file = new FileOperations('prefix-contract://nested/file.txt');
    $file->create('prefixed');

    expect($file->read())->toBe('prefixed')
        ->and(file_get_contents($this->adapterRoot . DIRECTORY_SEPARATOR . 'tenant-a/nested/file.txt'))
        ->toBe('prefixed');
});
