<?php

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('func_dir_', true);
    mkdir($this->tempDir);
    $this->tempFile = $this->tempDir . DIRECTORY_SEPARATOR . 'sample.txt';
    file_put_contents($this->tempFile, 'hello');
});

afterEach(function () {
    if (is_file($this->tempFile)) {
        unlink($this->tempFile);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

test('it formats file size to human readable text', function () {
    expect(getHumanReadableFileSize(1024))->toBe('1.00 KB');
});

test('it reports directory empty state correctly', function () {
    expect(isDirectoryEmpty($this->tempDir))->toBeFalse();

    unlink($this->tempFile);
    expect(isDirectoryEmpty($this->tempDir))->toBeTrue();
});

test('it throws for non-directory in isDirectoryEmpty', function () {
    expect(fn () => isDirectoryEmpty($this->tempFile))->toThrow(InvalidArgumentException::class);
});

test('it calculates directory size', function () {
    expect(getDirectorySize($this->tempDir))->toBe(filesize($this->tempFile));
});

test('it lists only files', function () {
    mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'nested');
    file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'inside.txt', 'x');

    try {
        expect(listFiles($this->tempDir))->toBe(['sample.txt']);
    } finally {
        unlink($this->tempDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'inside.txt');
        rmdir($this->tempDir . DIRECTORY_SEPARATOR . 'nested');
    }
});

test('it copies and deletes directory recursively', function () {
    $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('func_dest_', true);

    try {
        expect(copyDirectory($this->tempDir, $destination))->toBeTrue();
        expect(file_exists($destination . DIRECTORY_SEPARATOR . 'sample.txt'))->toBeTrue();
        expect(deleteDirectory($destination))->toBeTrue();
    } finally {
        if (is_file($destination . DIRECTORY_SEPARATOR . 'sample.txt')) {
            unlink($destination . DIRECTORY_SEPARATOR . 'sample.txt');
        }
        if (is_dir($destination)) {
            rmdir($destination);
        }
    }
});
