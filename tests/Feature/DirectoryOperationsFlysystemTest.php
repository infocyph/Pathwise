<?php

declare(strict_types=1);

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\Core\SyncComparison;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\FileManager\FileOperations;
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
        ->and($ops->copy('mnt://copied'))->toBe($ops)
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

    expect($sourceOps->zip('mnt://archives/archive.zip'))->toBe($sourceOps)
        ->and($destinationOps->unzip('mnt://archives/archive.zip'))->toBe($destinationOps)
        ->and(FlysystemHelper::read('mnt://zip-dst/a.txt'))->toBe('A')
        ->and(FlysystemHelper::read('mnt://zip-dst/nested/b.txt'))->toBe('B');
});

test('storage-neutral operations work while local-only capabilities are rejected on mounts', function () {
    $file = new FileOperations('mnt://neutral/file.txt');
    $file->create('first')->update('second');

    expect($file->read())->toBe('second')
        ->and($file->appendEmulated('-third'))->toBe($file)
        ->and($file->read())->toBe('second-third')
        ->and(fn () => $file->append('-native'))->toThrow(UnsupportedStorageOperationException::class)
        ->and(fn () => $file->getMetadata())->toThrow(UnsupportedStorageOperationException::class)
        ->and(fn () => $file->openWithLock())->toThrow(UnsupportedStorageOperationException::class);

    $directory = new DirectoryOperations('mnt://neutral');
    expect(fn () => $directory->getPermissions())->toThrow(UnsupportedStorageOperationException::class)
        ->and(fn () => $directory->setPermissions(0755))->toThrow(UnsupportedStorageOperationException::class)
        ->and(fn () => $directory->getIterator())->toThrow(UnsupportedStorageOperationException::class);
});

test('sync comparisons are explicit and progress totals remain unknown during lazy traversal', function () {
    FlysystemHelper::createDirectory('mnt://sync-source');
    FlysystemHelper::createDirectory('mnt://sync-target');
    FlysystemHelper::write('mnt://sync-source/same-size.txt', 'AAAA');
    FlysystemHelper::write('mnt://sync-target/same-size.txt', 'BBBB');
    $totals = [];

    $sizeReport = (new DirectoryOperations('mnt://sync-source'))->syncTo(
        'mnt://sync-target',
        false,
        function (array $event) use (&$totals): void {
            $totals[] = $event['total'];
        },
        SyncComparison::SIZE,
    );
    $checksumReport = (new DirectoryOperations('mnt://sync-source'))->syncTo(
        'mnt://sync-target',
        false,
        null,
        SyncComparison::CHECKSUM,
    );

    expect($sizeReport->unchanged)->toContain('same-size.txt')
        ->and($checksumReport->updated)->toContain('same-size.txt')
        ->and(FlysystemHelper::read('mnt://sync-target/same-size.txt'))->toBe('AAAA')
        ->and($totals)->each->toBeNull();
});
