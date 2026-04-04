<?php

use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->checksumDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('checksum_', true);
    mkdir($this->checksumDir, 0755, true);
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('checksum_mount_', true);
    mkdir($this->mountRoot, 0755, true);
    FlysystemHelper::mount('chk', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
});

afterEach(function () {
    FlysystemHelper::reset();

    if (!is_dir($this->checksumDir)) {
        if (is_dir($this->mountRoot)) {
            rmdir($this->mountRoot);
        }
        return;
    }

    foreach (scandir($this->checksumDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $this->checksumDir . DIRECTORY_SEPARATOR . $item;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($this->checksumDir);

    if (is_dir($this->mountRoot)) {
        foreach (scandir($this->mountRoot) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->mountRoot . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->mountRoot);
    }
});

test('it builds checksum index and finds duplicates', function () {
    $a = $this->checksumDir . DIRECTORY_SEPARATOR . 'a.txt';
    $b = $this->checksumDir . DIRECTORY_SEPARATOR . 'b.txt';
    $c = $this->checksumDir . DIRECTORY_SEPARATOR . 'c.txt';
    file_put_contents($a, 'same-content');
    file_put_contents($b, 'same-content');
    file_put_contents($c, 'different-content');

    $index = ChecksumIndexer::buildIndex($this->checksumDir);
    $duplicates = ChecksumIndexer::findDuplicates($this->checksumDir);

    expect($index)->not->toBeEmpty()
        ->and(count($duplicates))->toBe(1);
});

test('it attempts hard-link deduplication and keeps files accessible', function () {
    $a = $this->checksumDir . DIRECTORY_SEPARATOR . 'a.txt';
    $b = $this->checksumDir . DIRECTORY_SEPARATOR . 'b.txt';
    file_put_contents($a, 'same-content');
    file_put_contents($b, 'same-content');

    $report = ChecksumIndexer::deduplicateWithHardLinks($this->checksumDir);

    expect($report)->toHaveKeys(['linked', 'skipped'])
        ->and(is_file($a))->toBeTrue()
        ->and(is_file($b))->toBeTrue();
});

test('it builds duplicate index for mounted paths and skips hard links there', function () {
    FlysystemHelper::write('chk://a.txt', 'same-content');
    FlysystemHelper::write('chk://b.txt', 'same-content');
    FlysystemHelper::write('chk://c.txt', 'different-content');

    $duplicates = ChecksumIndexer::findDuplicates('chk://');
    $report = ChecksumIndexer::deduplicateWithHardLinks('chk://');

    expect(count($duplicates))->toBe(1)
        ->and($report['linked'])->toBe([])
        ->and(count($report['skipped']))->toBeGreaterThan(0);
});
