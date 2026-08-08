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

$officialDrivers = StorageFactory::officialDrivers();
$memoryAdapterClass = $officialDrivers['inmemory']['adapter_class'];
$readOnlyAdapterClass = $officialDrivers['read-only']['adapter_class'];
$pathPrefixingAdapterClass = $officialDrivers['path-prefixing']['adapter_class'];
$adapterClasses = [$memoryAdapterClass, $readOnlyAdapterClass, $pathPrefixingAdapterClass];

if (getenv('PATHWISE_REQUIRE_ADAPTER_CONTRACTS') === '1') {
    foreach ($adapterClasses as $adapterClass) {
        if (!class_exists($adapterClass)) {
            throw new RuntimeException("Required adapter contract dependency is unavailable: {$adapterClass}");
        }
    }
}

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

if (class_exists($memoryAdapterClass)) {
    test('storage-neutral contracts run against the in-memory adapter', function () {
        StorageFactory::mount('memory-contract', ['driver' => 'inmemory']);
        $file = new FileOperations('memory-contract://source/file.txt');
        $file->create('memory')->copy('memory-contract://source/copy.txt');
        $report = (new DirectoryOperations('memory-contract://source'))->syncTo('memory-contract://target');

        expect($file->read())->toBe('memory')
            ->and(FlysystemHelper::read('memory-contract://source/copy.txt'))->toBe('memory')
            ->and($report->created)->toContain('file.txt', 'copy.txt');
    });
}

if (class_exists($readOnlyAdapterClass)) {
    test('read-only adapters preserve reads and reject mutations', function () {
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
}

if (class_exists($pathPrefixingAdapterClass)) {
    test('path-prefixing adapters confine storage-neutral writes to their prefix', function () {
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
}
