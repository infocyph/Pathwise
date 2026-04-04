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
