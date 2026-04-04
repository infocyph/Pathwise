<?php

use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use Infocyph\Pathwise\FileManager\FileOperations;

beforeEach(function () {
    $this->filePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('test_file_', true).'.txt';
    $this->fileOperations = new FileOperations($this->filePath);
});

afterEach(function () {
    if (file_exists($this->filePath)) {
        unlink($this->filePath);
    }
});

test('it creates a file with optional content', function () {
    $this->fileOperations->create('Hello, world!');
    expect($this->fileOperations->exists())
        ->toBeTrue()
        ->and(file_get_contents($this->filePath))->toBe('Hello, world!');
});

test('it reads file content', function () {
    file_put_contents($this->filePath, 'Sample content');
    expect($this->fileOperations->read())->toBe('Sample content');
});

test('it throws an exception if file is not readable', function () {
    expect(fn () => $this->fileOperations->read())->toThrow(FileNotFoundException::class);
});

test('it updates file content', function () {
    $this->fileOperations->create('Old content');
    $this->fileOperations->update('New content');
    expect(file_get_contents($this->filePath))->toBe('New content');
});

test('it appends content to a file', function () {
    $this->fileOperations->create("Line 1\n");
    $this->fileOperations->append('Line 2');
    expect(file_get_contents($this->filePath))->toBe("Line 1\nLine 2");
});

test('it deletes a file', function () {
    $this->fileOperations->create();
    $this->fileOperations->delete();
    expect(file_exists($this->filePath))->toBeFalse();
});

test('it throws exception when deleting non-existent file', function () {
    expect(fn () => $this->fileOperations->delete())->toThrow(FileNotFoundException::class);
});

test('it gets file metadata', function () {
    $this->fileOperations->create('Hello, world!');
    $metadata = $this->fileOperations->getMetadata();

    expect($metadata)
        ->toHaveKeys(['permissions', 'size', 'last_modified', 'owner', 'group', 'type', 'mime_type', 'extension'])
        ->and($metadata['size'])->toBe(strlen('Hello, world!'))
        ->and($metadata['mime_type'])->toBe('text/plain');
});

test('it counts the lines in a file', function () {
    $this->fileOperations->create("Line 1\nLine 2\nLine 3");
    expect($this->fileOperations->getLineCount())->toBe(3);
});

test('it searches for content in a file', function () {
    $this->fileOperations->create("Find this line\nNot this one\nAnother find line");
    $results = $this->fileOperations->searchContent('find');
    expect($results)->toContain('Find this line', 'Another find line');
});

test('it renames or moves the file', function () {
    $newFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('new_file_', true).'.txt';
    $this->fileOperations->create('File content')->rename($newFilePath);
    expect(file_exists($newFilePath))->toBeTrue();
    unlink($newFilePath);
});

test('it copies the file to a new location', function () {
    $newFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('copy_file_', true).'.txt';
    $this->fileOperations->create('Copy content')->copy($newFilePath);
    expect(file_exists($newFilePath))
        ->toBeTrue()
        ->and(file_get_contents($newFilePath))->toBe('Copy content');
    unlink($newFilePath);
});

test('it sets file permissions', function () {
    $this->fileOperations->create();
    $this->fileOperations->setPermissions(0644);

    if (PHP_OS_FAMILY === 'Windows') {
        expect(is_readable($this->filePath))
            ->toBeTrue()
            ->and(is_writable($this->filePath))->toBeTrue();
    } else {
        // On Unix-based systems, check specific octal permissions.
        expect(fileperms($this->filePath) & 0777)->toBe(0644);
    }
});

test('it throws exception when setting invalid permissions', function () {
    $invalidFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'invalid_path.txt';
    $invalidFileOperations = new FileOperations($invalidFilePath);
    expect(fn () => $invalidFileOperations->setPermissions(0644))->toThrow(FileNotFoundException::class);
});

test('it throws file access exception when lock timeout is reached', function () {
    $this->fileOperations->create();
    $fp = fopen($this->filePath, 'r+');
    flock($fp, LOCK_EX);

    try {
        expect(fn () => $this->fileOperations->openWithLock(true, 1))->toThrow(FileAccessException::class);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
});

test('it locks and unlocks the file', function () {
    $this->fileOperations->create();
    $this->fileOperations->openWithLock();
    expect($this->fileOperations->unlock())->toBeInstanceOf(FileOperations::class);
});

test('it copies and verifies checksum', function () {
    $newFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('copy_verify_', true).'.txt';
    $this->fileOperations->create('verify this')->copyWithVerification($newFilePath);

    expect(file_exists($newFilePath))->toBeTrue();
    unlink($newFilePath);
});

test('it writes and verifies checksum', function () {
    $this->fileOperations->create('old');
    $this->fileOperations->writeAndVerify('new-content');

    expect($this->fileOperations->verifyChecksum(hash('sha256', 'new-content')))->toBeTrue();
});
