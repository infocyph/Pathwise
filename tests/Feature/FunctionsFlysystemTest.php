<?php

use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    FlysystemHelper::reset();
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('func_mount_', true);
    mkdir($this->mountRoot, 0755, true);
    FlysystemHelper::mount('mnt', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
});

afterEach(function () {
    FlysystemHelper::reset();
    if (is_dir($this->mountRoot)) {
        deleteDirectory($this->mountRoot);
    }
});

test('helper functions support mounted scheme paths', function () {
    createDirectory('mnt://helpers');
    FlysystemHelper::write('mnt://helpers/a.txt', 'abc');

    expect(isDirectoryEmpty('mnt://helpers'))->toBeFalse()
        ->and(getDirectorySize('mnt://helpers'))->toBe(3)
        ->and(listFiles('mnt://helpers'))->toBe(['a.txt'])
        ->and(copyDirectory('mnt://helpers', 'mnt://helpers-copy'))->toBeTrue()
        ->and(FlysystemHelper::fileExists('mnt://helpers-copy/a.txt'))->toBeTrue()
        ->and(deleteDirectory('mnt://helpers-copy'))->toBeTrue();
});

test('storage helper functions build and mount filesystems', function () {
    $rootA = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('func_storage_a_', true);
    $rootB = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('func_storage_b_', true);
    mkdir($rootA, 0755, true);
    mkdir($rootB, 0755, true);

    try {
        $filesystem = createFilesystem([
            'driver' => 'local',
            'root' => $rootA,
        ]);
        $filesystem->write('from_factory.txt', 'factory');

        mountStorage('alpha', ['driver' => 'local', 'root' => $rootA]);
        mountStorages([
            'beta' => ['driver' => 'local', 'root' => $rootB],
        ]);

        FlysystemHelper::write('alpha://hello.txt', 'A');
        FlysystemHelper::write('beta://hello.txt', 'B');

        expect($filesystem->read('from_factory.txt'))->toBe('factory')
            ->and(FlysystemHelper::read('alpha://hello.txt'))->toBe('A')
            ->and(FlysystemHelper::read('beta://hello.txt'))->toBe('B');
    } finally {
        FlysystemHelper::unmount('alpha');
        FlysystemHelper::unmount('beta');
        FlysystemHelper::deleteDirectory($rootA);
        FlysystemHelper::deleteDirectory($rootB);
    }
});
