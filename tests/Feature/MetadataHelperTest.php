<?php

use Infocyph\Pathwise\Utils\MetadataHelper;

beforeEach(function () {
    $this->tempFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('test_file_', true).'.txt';
    file_put_contents($this->tempFilePath, 'Hello World');

    $this->tempDirPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('test_dir_', true);
    mkdir($this->tempDirPath);
    file_put_contents($this->tempDirPath.DIRECTORY_SEPARATOR.'file1.txt', 'Content 1');
    file_put_contents($this->tempDirPath.DIRECTORY_SEPARATOR.'file2.txt', 'Content 2');

    $this->missingFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('missing_file_', true).'.txt';
    $this->missingDirPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('missing_dir_', true);
});

afterEach(function () {
    if (file_exists($this->tempFilePath)) {
        unlink($this->tempFilePath);
    }

    if (is_dir($this->tempDirPath)) {
        array_map('unlink', glob($this->tempDirPath.DIRECTORY_SEPARATOR.'*'));
        rmdir($this->tempDirPath);
    }
});

test('it retrieves file size in bytes', function () {
    $size = MetadataHelper::getFileSize($this->tempFilePath);
    expect($size)->toBe(filesize($this->tempFilePath));
});

test('it retrieves file size in human-readable format', function () {
    $size = MetadataHelper::getFileSize($this->tempFilePath, true);
    expect($size)->toBeString()->not->toBeNull();
});

test('it returns null for non-existent file size', function () {
    $size = MetadataHelper::getFileSize($this->missingFilePath);
    expect($size)->toBeNull();
});

test('it retrieves directory size', function () {
    $size = MetadataHelper::getDirectorySize($this->tempDirPath);
    expect($size)->toBeGreaterThan(0);
});

test('it returns null for non-existent directory size', function () {
    $size = MetadataHelper::getDirectorySize($this->missingDirPath);
    expect($size)->toBeNull();
});

test('it retrieves file count recursively', function () {
    $fileCount = MetadataHelper::getFileCount($this->tempDirPath);
    expect($fileCount)->toBe(2);
});

test('it retrieves file count non-recursively', function () {
    $fileCount = MetadataHelper::getFileCount($this->tempDirPath, false);
    expect($fileCount)->toBe(2);
});

test('it returns null for non-existent directory file count', function () {
    $fileCount = MetadataHelper::getFileCount($this->missingDirPath);
    expect($fileCount)->toBeNull();
});

test('it retrieves file timestamps', function () {
    $timestamps = MetadataHelper::getTimestamps($this->tempFilePath);
    expect($timestamps)->toHaveKeys(['created', 'modified', 'accessed']);
});

test('it retrieves human-readable file timestamps', function () {
    $timestamps = MetadataHelper::getHumanReadableTimestamps($this->tempFilePath);
    expect($timestamps)->toHaveKeys(['created', 'modified', 'accessed']);
    expect($timestamps['created'])->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
});

test('it returns null for non-existent file timestamps', function () {
    $timestamps = MetadataHelper::getTimestamps($this->missingFilePath);
    expect($timestamps)->toBeNull();
});

test('it retrieves file checksum', function () {
    $checksum = MetadataHelper::getChecksum($this->tempFilePath);
    expect($checksum)->toBeString()->not->toBeNull();
});

test('it returns null for unsupported checksum algorithm', function () {
    $checksum = MetadataHelper::getChecksum($this->tempFilePath, 'unsupported_algo');
    expect($checksum)->toBeNull();
});

test('it identifies broken symbolic link', function () {
    $linkPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'broken_link';
    $nonExistentPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonexistent_file.txt';
    symlink($nonExistentPath, $linkPath);

    expect(MetadataHelper::isBrokenSymlink($linkPath))->toBeTrue();
    unlink($linkPath);
})->skip(PHP_OS_FAMILY === 'Windows');;

test('it identifies non-broken symbolic link', function () {
    $linkPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'valid_link';
    symlink($this->tempFilePath, $linkPath);

    expect(MetadataHelper::isBrokenSymlink($linkPath))->toBeFalse();
    unlink($linkPath);
})->skip(PHP_OS_FAMILY === 'Windows');;

test('it returns null if path is not a symlink', function () {
    expect(MetadataHelper::isBrokenSymlink($this->tempFilePath))->toBeNull();
});

test('it retrieves mime type of file', function () {
    $mimeType = MetadataHelper::getMimeType($this->tempFilePath);
    expect($mimeType)->toBeString()->not->toBeNull();
});

test('it returns null for non-existent file mime type', function () {
    $mimeType = MetadataHelper::getMimeType($this->missingFilePath);
    expect($mimeType)->toBeNull();
});

test('it identifies file type', function () {
    $fileType = MetadataHelper::getPathType($this->tempFilePath);
    expect($fileType)->toBe('file');
});

test('it identifies directory type', function () {
    $fileType = MetadataHelper::getPathType($this->tempDirPath);
    expect($fileType)->toBe('directory');
});

test('it returns null for invalid path type', function () {
    $fileType = MetadataHelper::getPathType($this->missingFilePath);
    expect($fileType)->toBeNull();
});

test('it retrieves file ownership details', function () {
    $ownership = MetadataHelper::getOwnershipDetails($this->tempFilePath);
    expect($ownership)->toHaveKeys(['owner', 'group']);
})->skip(PHP_OS_FAMILY === 'Windows');

test('it retrieves last modified by user', function () {
    $lastModifiedBy = MetadataHelper::getLastModifiedBy($this->tempFilePath);
    expect($lastModifiedBy)->toBeString()->not->toBeNull();
});

test('it retrieves file extension', function () {
    $extension = MetadataHelper::getFileExtension($this->tempFilePath);
    expect($extension)->toBe('txt');
});

test('it returns null for directory extension', function () {
    $extension = MetadataHelper::getFileExtension($this->tempDirPath);
    expect($extension)->toBeNull();
});

test('it retrieves symlink target', function () {
    $linkPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'valid_link';
    symlink($this->tempFilePath, $linkPath);

    expect(MetadataHelper::getSymlinkTarget($linkPath))->toBe($this->tempFilePath);
    unlink($linkPath);
})->skip(PHP_OS_FAMILY === 'Windows');;

test('it returns null if path is not a symlink for target retrieval', function () {
    expect(MetadataHelper::getSymlinkTarget($this->tempFilePath))->toBeNull();
});

test('it identifies hidden file', function () {
    $hiddenFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'.hidden_file';
    touch($hiddenFilePath);

    expect(MetadataHelper::isHidden($hiddenFilePath))->toBeTrue();
    unlink($hiddenFilePath);
});

test('it retrieves all metadata for file', function () {
    $metadata = MetadataHelper::getAllMetadata($this->tempFilePath);
    expect($metadata)->toHaveKeys([
        'size',
        'file_count',
        'timestamps',
        'human_readable_timestamps',
        'mime_type',
        'type',
        'ownership',
        'last_modified_by',
        'extension',
        'is_hidden',
        'symlink_target',
        'is_broken_symlink',
    ]);
});
