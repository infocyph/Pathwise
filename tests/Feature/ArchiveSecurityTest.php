<?php

declare(strict_types=1);

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\Exceptions\CompressionException;
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

test('archive validation rejects special file entries', function () {
    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('unsafe-fifo', 'payload');
    $zip->setExternalAttributesName('unsafe-fifo', ZipArchive::OPSYS_UNIX, 0010644 << 16);
    $zip->close();

    expect(fn () => (new FileCompression($this->archivePath))->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class, 'Special-file ZIP entry');
});

test('archive validation rejects case conflicting entry names', function () {
    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('Report.txt', 'one');
    $zip->addFromString('report.txt', 'two');
    $zip->close();

    expect(fn () => (new FileCompression($this->archivePath))->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class, 'Duplicate or case-conflicting ZIP entry');
});

test('archive validation rejects file directory conflicts', function () {
    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('node', 'file');
    $zip->addFromString('node/child.txt', 'child');
    $zip->close();

    expect(fn () => (new DirectoryOperations($this->extractPath))->unzip($this->archivePath))
        ->toThrow(UnsafeArchiveEntryException::class, 'nested below an archive file');
});

test('archive validation rejects extraction through an existing destination symlink', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $zip = new ZipArchive();
        expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        $zip->addFromString('linked/escape.txt', '../outside.txt');
        $zip->setExternalAttributesName('linked/escape.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        expect(fn () => (new FileCompression($this->archivePath))->decompress($this->extractPath))
            ->toThrow(UnsafeArchiveEntryException::class, 'Symbolic-link ZIP entry');

        return;
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

test('batch extraction rejects duplicate output targets before writing', function () {
    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('one.txt', 'one');
    $zip->addFromString('two.txt', 'two');
    $zip->close();

    expect(fn () => (new FileCompression($this->archivePath))->batchExtractFiles(
        ['one.txt' => 'same.txt', 'two.txt' => 'SAME.txt'],
        $this->extractPath,
    ))->toThrow(CompressionException::class, 'same extraction path')
        ->and(file_exists($this->extractPath . DIRECTORY_SEPARATOR . 'same.txt'))->toBeFalse();
});

test('archive limits are checked before creating destination content', function () {
    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('one.txt', 'one');
    $zip->addFromString('two.txt', 'two');
    $zip->close();
    rmdir($this->extractPath);

    expect(fn () => (new FileCompression($this->archivePath))
        ->setExtractionLimits(maxEntries: 1)
        ->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class)
        ->and(is_dir($this->extractPath))->toBeFalse();
});

test('archive per-entry and compression-ratio limits reject oversized entries', function () {
    ($this->writeArchive)('large.txt', str_repeat('A', 4096));

    expect(fn () => (new FileCompression($this->archivePath))
        ->setExtractionLimits(maxEntryUncompressedBytes: 100)
        ->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class)
        ->and(fn () => (new FileCompression($this->archivePath))
            ->setExtractionLimits(maxCompressionRatio: 1.1)
            ->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class);
});

test('failed local extraction restores overwritten files and removes created files', function () {
    file_put_contents($this->extractPath . DIRECTORY_SEPARATOR . 'existing.txt', 'original');
    file_put_contents($this->extractPath . DIRECTORY_SEPARATOR . 'blocked', 'not-a-directory');

    $zip = new ZipArchive();
    expect($zip->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('existing.txt', 'replacement');
    $zip->addFromString('blocked/child.txt', 'child');
    $zip->close();

    expect(fn () => (new FileCompression($this->archivePath))->decompress($this->extractPath))
        ->toThrow(UnsafeArchiveEntryException::class)
        ->and(file_get_contents($this->extractPath . DIRECTORY_SEPARATOR . 'existing.txt'))->toBe('original')
        ->and(file_exists($this->extractPath . DIRECTORY_SEPARATOR . 'blocked' . DIRECTORY_SEPARATOR . 'child.txt'))->toBeFalse();
});

test('local ZIP creation refuses to follow symbolic links', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $source = $this->securityRoot . DIRECTORY_SEPARATOR . 'source';
    $outside = $this->securityRoot . DIRECTORY_SEPARATOR . 'outside.txt';
    mkdir($source, 0755, true);
    file_put_contents($outside, 'outside');
    symlink($outside, $source . DIRECTORY_SEPARATOR . 'linked.txt');

    expect(fn () => (new FileCompression($this->archivePath, true))->compress($source))
        ->toThrow(CompressionException::class, 'Symbolic links are not followed');
});
