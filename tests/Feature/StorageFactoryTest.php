<?php

declare(strict_types=1);

use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function (): void {
    FlysystemHelper::reset();
});

afterEach(function (): void {
    FlysystemHelper::reset();
});

test('it creates a local filesystem without mutating global routing state', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_local_', true);
    mkdir($root, 0755, true);

    try {
        $filesystem = StorageFactory::createFilesystem(['driver' => 'local', 'root' => $root]);
        $filesystem->write('a.txt', 'hello');

        expect($filesystem->read('a.txt'))->toBe('hello')
            ->and(FlysystemHelper::hasDefaultFilesystem())->toBeFalse()
            ->and(FlysystemHelper::hasMount('local'))->toBeFalse();
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it creates a filesystem from a provided adapter', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_adapter_', true);
    mkdir($root, 0755, true);

    try {
        $filesystem = StorageFactory::createFilesystem(['adapter' => new LocalFilesystemAdapter($root)]);
        $filesystem->write('b.txt', 'world');

        expect($filesystem->read('b.txt'))->toBe('world');
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it returns a provided filesystem instance as-is', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_passthrough_', true);
    mkdir($root, 0755, true);

    try {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($root));
        expect(StorageFactory::createFilesystem(['filesystem' => $filesystem]))->toBe($filesystem);
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it exposes official adapter metadata and package lookup', function (): void {
    $official = StorageFactory::officialDrivers();

    expect($official)->toHaveKeys([
        'local', 'ftp', 'inmemory', 'read-only', 'path-prefixing', 'aws-s3', 'async-aws-s3',
        'azure-blob-storage', 'google-cloud-storage', 'mongodb-gridfs', 'sftp-v2', 'sftp-v3',
        'webdav', 'ziparchive',
    ])
        ->and(StorageFactory::suggestedPackage('s3'))->toBe('league/flysystem-aws-s3-v3')
        ->and(StorageFactory::suggestedPackage('in-memory'))->toBe('league/flysystem-memory')
        ->and(StorageFactory::suggestedPackage('zip'))->toBe('league/flysystem-ziparchive');
});

test('unsupported custom drivers direct callers to StorageContext', function (): void {
    expect(fn () => StorageFactory::createFilesystem(['driver' => 'tenant-driver']))
        ->toThrow(InvalidArgumentException::class, 'StorageContext');
});

test('it throws for local driver without root', function (): void {
    expect(fn () => StorageFactory::createFilesystem(['driver' => 'local']))
        ->toThrow(InvalidArgumentException::class, 'Local driver requires a non-empty "root" path');
});

test('it provides package guidance for missing official drivers', function (): void {
    $metadata = StorageFactory::officialDrivers()['aws-s3'];
    if (!class_exists($metadata['adapter_class'])) {
        expect(fn () => StorageFactory::createFilesystem(['driver' => 's3']))
            ->toThrow(InvalidArgumentException::class, $metadata['package']);

        return;
    }

    expect(fn () => StorageFactory::createFilesystem(['driver' => 's3']))
        ->toThrow(InvalidArgumentException::class, "requires either 'adapter' or 'constructor'");
});

test('it supports in-memory driver when the optional adapter exists', function (): void {
    $metadata = StorageFactory::officialDrivers()['inmemory'];
    if (!class_exists($metadata['adapter_class'])) {
        expect(fn () => StorageFactory::createFilesystem(['driver' => 'in-memory']))
            ->toThrow(InvalidArgumentException::class, $metadata['package']);

        return;
    }

    $filesystem = StorageFactory::createFilesystem(['driver' => 'in-memory']);
    $filesystem->write('memory.txt', 'memory-data');

    expect($filesystem->read('memory.txt'))->toBe('memory-data');
});

test('it rejects conflicting configuration modes and malformed options', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_conflict_', true);
    mkdir($root);
    $adapter = new LocalFilesystemAdapter($root);

    try {
        expect(fn () => StorageFactory::createFilesystem(['adapter' => $adapter, 'driver' => 'local', 'root' => $root]))
            ->toThrow(InvalidArgumentException::class, 'exactly one')
            ->and(fn () => StorageFactory::createFilesystem(['adapter' => $adapter, 'options' => ['bad']]))
            ->toThrow(InvalidArgumentException::class, 'keys must be strings');
    } finally {
        rmdir($root);
    }
});
