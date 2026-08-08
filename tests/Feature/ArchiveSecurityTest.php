<?php

declare(strict_types=1);

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\Security\ZipEntryValidator;

beforeEach(function () {
    $this->securityRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_zip_security_', true);
    $this->archivePath = $this->securityRoot . DIRECTORY_SEPARATOR . 'archive.zip';
    $this->extractPath = $this->securityRoot . DIRECTORY_SEPARATOR . 'extract';
    mkdir($this->securityRoot, 0755, true);
    mkdir($this->extractPath, 0755, true);

    $this->writeArchive = function (string $entry, string $contents = 'blocked'): void {
        $zip = new ZipArchive();
        expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        $zip->addFromString($entry, $contents);
        $zip->close();
    };
});

afterEach(function () {
    if (!is_dir($this->securityRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->securityRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($this->securityRoot);
});

test('all extraction APIs reject traversal archive entries', function () {
    ($this->writeArchive)('../outside.txt');

    expect(fn () => (new FileCompression($this->archivePath))->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class)
        ->and(fn () => (new FileCompression($this->archivePath))->batchExtractFiles(
            ['../outside.txt' => 'selected.txt'],
            $this->extractPath,
        ))->toThrow(UnsafeArchiveEntryException::class)
        ->and(fn () => (new DirectoryOperations($this->extractPath))->unzip($this->archivePath))
        ->toThrow(UnsafeArchiveEntryException::class)
        ->and(file_exists($this->securityRoot . DIRECTORY_SEPARATOR . 'outside.txt'))->toBeFalse();
});

test('archive validation rejects absolute and Windows drive paths', function (string $entry) {
    ($this->writeArchive)($entry);

    expect(fn () => (new FileCompression($this->archivePath))->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class);
})->with(['/absolute.txt', 'C:/windows.txt', 'C:drive-relative.txt', '\\\\server\\share.txt']);

test('entry validation rejects null bytes', function () {
    expect(fn () => ZipEntryValidator::validate("safe\0evil.txt", $this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class, 'Unsafe ZIP entry path');
});

test('archive validation rejects symbolic link entries', function () {
    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('unsafe-link', '../outside.txt');
    $zip->setExternalAttributesName('unsafe-link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();

    expect(fn () => (new DirectoryOperations($this->extractPath))->unzip($this->archivePath))
        ->toThrow(UnsafeArchiveEntryException::class, 'Symbolic-link ZIP entry');
});

test('archive validation rejects extraction through an existing destination symlink', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symbolic-link creation is not consistently available on Windows CI.');
    }

    $outside = $this->securityRoot . DIRECTORY_SEPARATOR . 'outside';
    mkdir($outside, 0755, true);
    symlink($outside, $this->extractPath . DIRECTORY_SEPARATOR . 'linked');
    ($this->writeArchive)('linked/escape.txt');

    expect(fn () => (new FileCompression($this->archivePath))->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class, 'symbolic link')
        ->and(file_exists($outside . DIRECTORY_SEPARATOR . 'escape.txt'))->toBeFalse();
});

test('batch extraction validates unselected entries before writing selected files', function () {
    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('safe.txt', 'safe');
    $zip->addFromString('../unsafe.txt', 'unsafe');
    $zip->close();

    expect(fn () => (new FileCompression($this->archivePath))->batchExtractFiles(
        ['safe.txt' => 'safe.txt'],
        $this->extractPath,
    ))->toThrow(UnsafeArchiveEntryException::class)
        ->and(file_exists($this->extractPath . DIRECTORY_SEPARATOR . 'safe.txt'))->toBeFalse();
});
