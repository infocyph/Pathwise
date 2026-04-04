<?php

use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    FlysystemHelper::reset();
    $this->helperDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('fly_', true);
    FlysystemHelper::createDirectory($this->helperDir);
});

afterEach(function () {
    if (is_dir($this->helperDir)) {
        FlysystemHelper::deleteDirectory($this->helperDir);
    }
    FlysystemHelper::reset();
});

test('it performs basic file lifecycle operations', function () {
    $source = $this->helperDir . DIRECTORY_SEPARATOR . 'a.txt';
    $destination = $this->helperDir . DIRECTORY_SEPARATOR . 'b.txt';

    FlysystemHelper::write($source, 'hello');

    expect(FlysystemHelper::fileExists($source))->toBeTrue()
        ->and(FlysystemHelper::read($source))->toBe('hello');

    FlysystemHelper::copy($source, $destination);
    expect(FlysystemHelper::read($destination))->toBe('hello');

    FlysystemHelper::move($destination, $source . '.moved');
    expect(FlysystemHelper::fileExists($source . '.moved'))->toBeTrue();
});

test('it supports stream, checksum, has and listings', function () {
    $filePath = $this->helperDir . DIRECTORY_SEPARATOR . 'stream.txt';
    $stream = fopen('php://temp', 'rb+');
    fwrite($stream, 'stream-content');
    rewind($stream);

    FlysystemHelper::writeStream($filePath, $stream);
    fclose($stream);

    expect(FlysystemHelper::has($filePath))->toBeTrue()
        ->and(FlysystemHelper::checksum($filePath, 'sha256'))->toBe(hash('sha256', 'stream-content'))
        ->and(FlysystemHelper::size($filePath))->toBe(strlen('stream-content'))
        ->and(FlysystemHelper::lastModified($filePath))->toBeInt();

    $readStream = FlysystemHelper::readStream($filePath);
    expect(is_resource($readStream))->toBeTrue();
    fclose($readStream);

    $listing = FlysystemHelper::listContentsListing($this->helperDir, true);
    expect($listing->toArray())->not->toBeEmpty();
});

test('it supports mounted filesystems using scheme paths', function () {
    $mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('fly_mount_', true);
    mkdir($mountRoot, 0755, true);

    try {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($mountRoot));
        FlysystemHelper::mount('mnt', $filesystem);

        FlysystemHelper::write('mnt://hello.txt', 'world');
        expect(FlysystemHelper::read('mnt://hello.txt'))->toBe('world')
            ->and(FlysystemHelper::fileExists('mnt://hello.txt'))->toBeTrue();
    } finally {
        FlysystemHelper::unmount('mnt');
        FlysystemHelper::deleteDirectory($mountRoot);
    }
});

test('it supports default filesystem for relative paths', function () {
    $defaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('fly_default_', true);
    mkdir($defaultRoot, 0755, true);

    try {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($defaultRoot));
        FlysystemHelper::setDefaultFilesystem($filesystem);

        FlysystemHelper::write('relative/file.txt', 'relative-data');
        expect(FlysystemHelper::read('relative/file.txt'))->toBe('relative-data')
            ->and(FlysystemHelper::directoryExists('relative'))->toBeTrue();
    } finally {
        FlysystemHelper::clearDefaultFilesystem();
        FlysystemHelper::deleteDirectory($defaultRoot);
    }
});

test('it supports directory copy and move operations', function () {
    $sourceDir = $this->helperDir . DIRECTORY_SEPARATOR . 'src';
    $destinationDir = $this->helperDir . DIRECTORY_SEPARATOR . 'dst';
    $movedDir = $this->helperDir . DIRECTORY_SEPARATOR . 'moved';
    FlysystemHelper::createDirectory($sourceDir);
    FlysystemHelper::write($sourceDir . DIRECTORY_SEPARATOR . 'a.txt', 'A');
    FlysystemHelper::createDirectory($sourceDir . DIRECTORY_SEPARATOR . 'nested');
    FlysystemHelper::write($sourceDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'b.txt', 'B');

    FlysystemHelper::copyDirectory($sourceDir, $destinationDir);
    expect(FlysystemHelper::fileExists($destinationDir . DIRECTORY_SEPARATOR . 'a.txt'))->toBeTrue()
        ->and(FlysystemHelper::fileExists($destinationDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'b.txt'))->toBeTrue();

    FlysystemHelper::moveDirectory($destinationDir, $movedDir);
    expect(FlysystemHelper::directoryExists($movedDir))->toBeTrue()
        ->and(FlysystemHelper::directoryExists($destinationDir))->toBeFalse();
});

test('it throws for unsupported url generation on local adapter', function () {
    $filePath = $this->helperDir . DIRECTORY_SEPARATOR . 'url.txt';
    FlysystemHelper::write($filePath, 'url-test');

    expect(fn () => FlysystemHelper::publicUrl($filePath))->toThrow(RuntimeException::class)
        ->and(fn () => FlysystemHelper::temporaryUrl($filePath, new DateTimeImmutable('+1 hour')))->toThrow(RuntimeException::class);
});
