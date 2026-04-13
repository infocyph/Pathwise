<?php

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\Exceptions\DirectoryOperationException;

// Helper function to create a temporary directory for testing
function createTempDirectory(): string
{
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_dir_', true) . random_int(0, 99);
    mkdir($tempDir);

    return $tempDir;
}

beforeEach(function () {
    $this->tempDir = createTempDirectory();
    $this->directoryOperations = new DirectoryOperations($this->tempDir);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        (new DirectoryOperations($this->tempDir))->delete(true);
    }
});

test('can create a directory', function () {
    $newDir = $this->tempDir . DIRECTORY_SEPARATOR . uniqid('new_dir_', true);
    $dirOps = new DirectoryOperations($newDir);
    expect($dirOps->create())
        ->toBeTrue()
        ->and(is_dir($newDir))->toBeTrue();
});

test('create is idempotent when directory already exists', function () {
    expect($this->directoryOperations->create())
        ->toBeTrue()
        ->and($this->directoryOperations->create())->toBeTrue()
        ->and(is_dir($this->tempDir))->toBeTrue();
});

test('can delete a directory', function () {
//    $this->directoryOperations->create();
    expect($this->directoryOperations->delete())
        ->toBeTrue()
        ->and(is_dir($this->tempDir))->toBeFalse();
});

test('can copy a directory', function () {
    $destDir = createTempDirectory();
//    $this->directoryOperations->create();
    $fileName = uniqid('test_', true) . '.txt';
    file_put_contents($this->tempDir . '/' . $fileName, 'sample content');
    $this->directoryOperations->copy($destDir);

    expect(is_dir($destDir))
        ->toBeTrue()
        ->and(file_exists($destDir . '/' . $fileName))->toBeTrue()
        ->and(file_get_contents($destDir . '/' . $fileName))->toBe('sample content');
});

test('copy emits progress callback events', function () {
    $destDir = createTempDirectory();
    file_put_contents($this->tempDir . '/' . uniqid('a_', true) . '.txt', 'A');
    file_put_contents($this->tempDir . '/' . uniqid('b_', true) . '.txt', 'B');

    $events = [];
    try {
        $this->directoryOperations->copy($destDir, function (array $event) use (&$events) {
            $events[] = $event;
        });

        expect($events)->not->toBeEmpty()
            ->and($events[count($events) - 1]['operation'])->toBe('copy');
    } finally {
        (new DirectoryOperations($destDir))->delete(true);
    }
});

test('can move a directory', function () {
    $destDir = createTempDirectory();
    $newLocation = $destDir . DIRECTORY_SEPARATOR . uniqid('moved_dir_', true);
    $result = $this->directoryOperations->move($newLocation);

    expect($result)
        ->toBeTrue()
        ->and(is_dir($this->tempDir))->toBeFalse()
        ->and(is_dir($newLocation))->toBeTrue();
});

test('can list directory contents', function () {
    $file1 = $this->tempDir . '/' . uniqid('test_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('example_', true) . '.txt';
    file_put_contents($file1, 'sample content');
    file_put_contents($file2, 'example content');

    $contents = $this->directoryOperations->listContents();
    $contents = array_map(fn($path) => realpath($path), $contents);

    expect($contents)->toContain(realpath($file1), realpath($file2));
});

test('can list directory contents with details', function () {
    $file = $this->tempDir . '/' . uniqid('test_', true) . '.txt';
    file_put_contents($file, 'sample content');

    $contents = $this->directoryOperations->listContents(true);
    $fileInfo = $contents[0];

    expect($fileInfo)
        ->toHaveKeys(['path', 'type', 'size', 'permissions', 'last_modified'])
        ->and($fileInfo['type'])->toBe('file');
});

test('can get and set directory permissions', function () {
    $this->directoryOperations->setPermissions(0777);
    expect($this->directoryOperations->getPermissions())->toBe(0777);
});

test('can calculate directory size', function () {
    $file1 = $this->tempDir . '/' . uniqid('file1_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('file2_', true) . '.txt';
    file_put_contents($file1, str_repeat('A', 1024)); // 1 KB
    file_put_contents($file2, str_repeat('B', 2048)); // 2 KB

    expect($this->directoryOperations->size())->toBe(1024 + 2048);
});

test('can flatten directory contents', function () {
    $subdir = $this->tempDir . '/' . uniqid('subdir_', true);
    mkdir($subdir);
    $file1 = $subdir . '/' . uniqid('file1_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('file2_', true) . '.txt';
    file_put_contents($file1, 'content');
    file_put_contents($file2, 'more content');

    $flattened = $this->directoryOperations->flatten();
    $flattened = array_map(fn($path) => realpath($path), $flattened);

    expect($flattened)->toContain(realpath($file1), realpath($file2));
});

test('can zip directory contents', function () {
    $file = $this->tempDir . '/' . uniqid('file_', true) . '.txt';
    file_put_contents($file, 'zip test');

    $zipPath = $this->tempDir . '/' . uniqid('archive_', true) . '.zip';
    $this->directoryOperations->zip($zipPath);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->numFiles)
        ->toBe(1)
        ->and($zip->getNameIndex(0))->toBe(basename($file));
    $zip->close();
});

test('can unzip archive', function () {
    // Create a zip file with a sample file
    $zipPath = $this->tempDir . '/' . uniqid('archive_', true) . '.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $fileName = uniqid('file_', true) . '.txt';
    $zip->addFromString($fileName, 'sample content');
    $zip->close();

    // Unzip to a new directory
    $unzipDir = createTempDirectory();
    $dirOps = new DirectoryOperations($unzipDir);
    $dirOps->unzip($zipPath);

    expect(file_exists($unzipDir . '/' . $fileName))
        ->toBeTrue()
        ->and(file_get_contents($unzipDir . '/' . $fileName))->toBe('sample content');
});

test('finds files based on criteria', function () {
    $file1 = $this->tempDir . '/' . uniqid('file1_', true) . '.txt';
    $file2 = $this->tempDir . '/' . uniqid('file2_', true) . '.md';
    file_put_contents($file1, 'file one');
    file_put_contents($file2, 'file two');

    // Set permissions compatible across platforms
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        chmod($file1, 0644);
    }

    $foundFiles = $this->directoryOperations->find([
        'name' => basename($file1),
        'extension' => 'txt',
        'permissions' => 0644,
    ]);

    $foundFiles = array_map(fn($path) => realpath($path), $foundFiles);

    expect($foundFiles)
        ->toHaveCount(1)
        ->and($foundFiles[0])->toEndWith(basename($file1));
});

test('it syncs directory and returns diff report', function () {
    $sourceFile = $this->tempDir . '/' . 'sync.txt';
    file_put_contents($sourceFile, 'v1');

    $destDir = createTempDirectory();
    file_put_contents($destDir . '/' . 'old.txt', 'old');

    $events = [];
    $report = $this->directoryOperations->syncTo($destDir, true, function (array $event) use (&$events) {
        $events[] = $event;
    });

    expect($report)->toHaveKeys(['created', 'updated', 'deleted', 'unchanged'])
        ->and($report['created'])->toContain('sync.txt')
        ->and($report['deleted'])->toContain('old.txt')
        ->and($events)->not->toBeEmpty();

    unlink($destDir . '/' . 'sync.txt');
    rmdir($destDir);
});

test('zip throws when source directory does not exist', function () {
    $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('missing_', true);
    $ops = new DirectoryOperations($missing);

    expect(fn () => $ops->zip($this->tempDir . DIRECTORY_SEPARATOR . 'out.zip'))
        ->toThrow(DirectoryOperationException::class);
});

test('unzip throws when source archive does not exist', function () {
    expect(fn () => $this->directoryOperations->unzip($this->tempDir . DIRECTORY_SEPARATOR . 'missing.zip'))
        ->toThrow(DirectoryOperationException::class);
});

test('unzip rejects zip-slip traversal entries', function () {
    $zipPath = $this->tempDir . DIRECTORY_SEPARATOR . uniqid('archive_', true) . '.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('../outside_zip_slip.txt', 'malicious');
    $zip->close();

    $unzipDir = createTempDirectory();
    $outsidePath = dirname($unzipDir) . DIRECTORY_SEPARATOR . 'outside_zip_slip.txt';

    if (file_exists($outsidePath)) {
        unlink($outsidePath);
    }

    try {
        $dirOps = new DirectoryOperations($unzipDir);

        expect(fn () => $dirOps->unzip($zipPath))
            ->toThrow(DirectoryOperationException::class, 'Unsafe ZIP entry path');

        expect(file_exists($outsidePath))->toBeFalse();
    } finally {
        if (is_dir($unzipDir)) {
            (new DirectoryOperations($unzipDir))->delete(true);
        }
        if (file_exists($outsidePath)) {
            unlink($outsidePath);
        }
    }
});
