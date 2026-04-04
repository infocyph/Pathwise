<?php

use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_dir_', true);
    mkdir($this->tempDir);

    $this->file1 = $this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt';
    $this->file2 = $this->tempDir . DIRECTORY_SEPARATOR . 'file2.txt';
    file_put_contents($this->file1, 'This is the first test file.');
    file_put_contents($this->file2, 'This is the second test file.');

    $this->zipFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_zip_', true) . '.zip';
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('compress_mount_', true);
    mkdir($this->mountRoot, 0755, true);
    FlysystemHelper::mount('zipmnt', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
});

afterEach(function () {
    FlysystemHelper::reset();

    foreach (array_diff(scandir($this->tempDir), ['.', '..']) as $item) {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $item;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($this->tempDir);
    if (file_exists($this->zipFilePath)) {
        unlink($this->zipFilePath);
    }

    if (is_dir($this->mountRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->mountRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->mountRoot);
    }
});

// Test creating a ZIP archive and compressing files
test('it creates a ZIP archive and compresses files', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    expect(file_exists($this->zipFilePath))->toBeTrue();

    $filesInZip = $compressor->listFiles();
    expect($filesInZip)
        ->toContain('file1.txt', 'file2.txt')
        ->and($compressor->fileCount())->toBe(2);
});

// Test decompressing a ZIP archive
test('it decompresses a ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    $decompressDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('decompress_', true);
    mkdir($decompressDir);

    $compressor->decompress($decompressDir);

    expect(file_exists($decompressDir . DIRECTORY_SEPARATOR . 'file1.txt'))
        ->toBeTrue()
        ->and(file_exists($decompressDir . DIRECTORY_SEPARATOR . 'file2.txt'))->toBeTrue();

    array_map('unlink', glob($decompressDir . DIRECTORY_SEPARATOR . '*'));
    rmdir($decompressDir);
});

// Test compressing files with a password
test('it compresses files with a password and encrypts them', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->setPassword('securepassword')->compress($this->tempDir)->save();

    expect($compressor->fileCount())
        ->toBe(2)
        ->and($compressor->listFiles())->toContain('file1.txt', 'file2.txt');
});

// Test decompressing a password-protected ZIP with wrong password
test('it fails to decompress with an incorrect password', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->setPassword('securepassword')->compress($this->tempDir)->save();

    $decompressDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('decompress_', true);
    mkdir($decompressDir);

    $failedDecompressor = new FileCompression($this->zipFilePath);
    $failedDecompressor->setPassword('wrongpassword');

    expect(fn () => $failedDecompressor->decompress($decompressDir))
        ->toThrow(CompressionException::class, 'Failed to extract ZIP archive');

    rmdir($decompressDir);
});

// Test adding individual files
test('it adds individual files to a ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->addFile($this->file1)->addFile($this->file2)->save();

    expect($compressor->fileCount())
        ->toBe(2)
        ->and($compressor->listFiles())->toContain('file1.txt', 'file2.txt');
});

// Test batch adding files
test('it handles batch adding files', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->batchAddFiles([$this->file1 => 'file1.txt', $this->file2 => 'file2.txt'])->save();

    expect($compressor->fileCount())
        ->toBe(2)
        ->and($compressor->listFiles())->toContain('file1.txt', 'file2.txt');
});

// Test hooks
test('it triggers hooks during file operations', function () {
    $beforeAdd = false;
    $afterAdd = false;

    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->registerHook('beforeAdd', function () use (&$beforeAdd) {
        $beforeAdd = true;
    })->registerHook('afterAdd', function () use (&$afterAdd) {
        $afterAdd = true;
    });

    $compressor->addFile($this->file1)->save();

    expect($beforeAdd)
        ->toBeTrue()
        ->and($afterAdd)->toBeTrue();
});

// Test file iterator
test('it retrieves a file iterator for the ZIP archive', function () {
    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    $files = iterator_to_array($compressor->getFileIterator());
    expect($files)->toContain('file1.txt', 'file2.txt');
});

test('it throws compression exception for invalid source path', function () {
    $compressor = new FileCompression($this->zipFilePath, true);

    expect(fn () => $compressor->compress($this->tempDir . DIRECTORY_SEPARATOR . 'missing-source'))
        ->toThrow(CompressionException::class, 'Source path does not exist');
});

test('it throws compression exception when adding missing file', function () {
    $compressor = new FileCompression($this->zipFilePath, true);

    expect(fn () => $compressor->addFile($this->tempDir . DIRECTORY_SEPARATOR . 'missing.txt'))
        ->toThrow(CompressionException::class, 'File does not exist');
});

test('it supports include and exclude glob patterns', function () {
    file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'keep.log', 'keep');
    file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'skip.tmp', 'skip');

    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor
        ->setGlobPatterns(['*.txt', '*.log'], ['*.tmp'])
        ->compress($this->tempDir)
        ->save();

    expect($compressor->listFiles())
        ->toContain('file1.txt', 'file2.txt', 'keep.log')
        ->not->toContain('skip.tmp');
});

test('it emits progress events during compress and decompress', function () {
    $events = [];

    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor
        ->setProgressCallback(function (array $event) use (&$events) {
            $events[] = $event;
        })
        ->compress($this->tempDir)
        ->save();

    $decompressDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('decompress_progress_', true);
    mkdir($decompressDir);

    try {
        $compressor->decompress($decompressDir);
        expect($events)->not->toBeEmpty();
        expect(array_column($events, 'operation'))->toContain('compress', 'decompress');
    } finally {
        array_map('unlink', glob($decompressDir . DIRECTORY_SEPARATOR . '*'));
        rmdir($decompressDir);
    }
});

test('it respects ignore file patterns from source root', function () {
    file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . '.pathwiseignore', "*.tmp\n");
    file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'ignored.tmp', 'ignore me');

    $compressor = new FileCompression($this->zipFilePath, true);
    $compressor->compress($this->tempDir)->save();

    expect($compressor->listFiles())->not->toContain('ignored.tmp');
});

test('it supports mounted zip and source paths', function () {
    FlysystemHelper::createDirectory('zipmnt://src');
    FlysystemHelper::write('zipmnt://src/a.txt', 'A');
    FlysystemHelper::createDirectory('zipmnt://src/nested');
    FlysystemHelper::write('zipmnt://src/nested/b.txt', 'B');

    $compressor = new FileCompression('zipmnt://archives/mounted.zip', true);
    $compressor->compress('zipmnt://src')->save();

    $extractor = new FileCompression('zipmnt://archives/mounted.zip');
    $extractor->decompress('zipmnt://dst');

    expect(FlysystemHelper::read('zipmnt://dst/a.txt'))->toBe('A')
        ->and(FlysystemHelper::read('zipmnt://dst/nested/b.txt'))->toBe('B');
});
