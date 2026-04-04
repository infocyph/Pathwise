<?php

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    FlysystemHelper::reset();
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('dir_ops_mount_', true);
    mkdir($this->mountRoot, 0755, true);

    FlysystemHelper::mount('mnt', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
});

afterEach(function () {
    FlysystemHelper::reset();
    if (is_dir($this->mountRoot)) {
        (new DirectoryOperations($this->mountRoot))->delete(true);
    }
});

test('directory operations support mounted scheme paths', function () {
    FlysystemHelper::createDirectory('mnt://source');
    FlysystemHelper::createDirectory('mnt://source/nested');
    FlysystemHelper::write('mnt://source/file.txt', 'hello');
    FlysystemHelper::write('mnt://source/nested/inside.txt', 'world');

    $ops = new DirectoryOperations('mnt://source');

    expect($ops->size())->toBe(strlen('hello') + strlen('world'))
        ->and($ops->copy('mnt://copied'))->toBeTrue()
        ->and(FlysystemHelper::fileExists('mnt://copied/file.txt'))->toBeTrue()
        ->and(FlysystemHelper::fileExists('mnt://copied/nested/inside.txt'))->toBeTrue();
});

test('zip and unzip support mounted scheme paths', function () {
    FlysystemHelper::createDirectory('mnt://zip-src');
    FlysystemHelper::write('mnt://zip-src/a.txt', 'A');
    FlysystemHelper::createDirectory('mnt://zip-src/nested');
    FlysystemHelper::write('mnt://zip-src/nested/b.txt', 'B');

    $sourceOps = new DirectoryOperations('mnt://zip-src');
    $destinationOps = new DirectoryOperations('mnt://zip-dst');

    expect($sourceOps->zip('mnt://archives/archive.zip'))->toBeTrue()
        ->and($destinationOps->unzip('mnt://archives/archive.zip'))->toBeTrue()
        ->and(FlysystemHelper::read('mnt://zip-dst/a.txt'))->toBe('A')
        ->and(FlysystemHelper::read('mnt://zip-dst/nested/b.txt'))->toBe('B');
});
