<?php

use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    FlysystemHelper::reset();
    StorageFactory::clearDrivers();
});

afterEach(function () {
    FlysystemHelper::reset();
    StorageFactory::clearDrivers();
});

test('it creates a local filesystem from driver config', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_local_', true);
    mkdir($root, 0755, true);

    try {
        $filesystem = StorageFactory::createFilesystem([
            'driver' => 'local',
            'root' => $root,
        ]);

        $filesystem->write('a.txt', 'hello');

        expect($filesystem->read('a.txt'))->toBe('hello');
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it mounts a filesystem from local driver config', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_mount_', true);
    mkdir($root, 0755, true);

    try {
        StorageFactory::mount('assets', [
            'driver' => 'local',
            'root' => $root,
        ]);

        FlysystemHelper::write('assets://reports/q1.txt', 'Q1');

        expect(FlysystemHelper::read('assets://reports/q1.txt'))->toBe('Q1');
    } finally {
        FlysystemHelper::unmount('assets');
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it creates a filesystem from a provided adapter', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_adapter_', true);
    mkdir($root, 0755, true);

    try {
        $filesystem = StorageFactory::createFilesystem([
            'adapter' => new LocalFilesystemAdapter($root),
        ]);

        $filesystem->write('b.txt', 'world');

        expect($filesystem->read('b.txt'))->toBe('world');
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it returns the provided filesystem instance as-is', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_passthrough_', true);
    mkdir($root, 0755, true);

    try {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($root));
        $resolved = StorageFactory::createFilesystem(['filesystem' => $filesystem]);

        expect($resolved)->toBe($filesystem);
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it supports custom registered drivers', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_custom_', true);
    mkdir($root, 0755, true);

    StorageFactory::registerDriver('custom-local', function (array $config) use ($root): Filesystem {
        $base = (string) ($config['root'] ?? $root);

        return new Filesystem(new LocalFilesystemAdapter($base));
    });

    try {
        StorageFactory::mount('custom', [
            'driver' => 'custom-local',
            'root' => $root,
        ]);

        FlysystemHelper::write('custom://nested/file.txt', 'custom-data');

        expect(FlysystemHelper::read('custom://nested/file.txt'))->toBe('custom-data')
            ->and(StorageFactory::hasDriver('custom-local'))->toBeTrue()
            ->and(StorageFactory::driverNames())->toContain('custom-local');
    } finally {
        FlysystemHelper::unmount('custom');
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it mounts multiple storages from config map', function () {
    $rootA = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_many_a_', true);
    $rootB = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('storage_many_b_', true);
    mkdir($rootA, 0755, true);
    mkdir($rootB, 0755, true);

    try {
        StorageFactory::mountMany([
            'a' => ['driver' => 'local', 'root' => $rootA],
            'b' => ['driver' => 'local', 'root' => $rootB],
        ]);

        FlysystemHelper::write('a://one.txt', 'A');
        FlysystemHelper::write('b://two.txt', 'B');

        expect(FlysystemHelper::read('a://one.txt'))->toBe('A')
            ->and(FlysystemHelper::read('b://two.txt'))->toBe('B');
    } finally {
        FlysystemHelper::unmount('a');
        FlysystemHelper::unmount('b');
        FlysystemHelper::deleteDirectory($rootA);
        FlysystemHelper::deleteDirectory($rootB);
    }
});

test('it throws for unsupported driver', function () {
    expect(fn () => StorageFactory::createFilesystem(['driver' => 's3']))
        ->toThrow(InvalidArgumentException::class, 'Unsupported storage driver');
});

test('it throws for local driver without root', function () {
    expect(fn () => StorageFactory::createFilesystem(['driver' => 'local']))
        ->toThrow(InvalidArgumentException::class, 'Local driver requires a non-empty "root" path');
});
