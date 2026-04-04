<?php

use Infocyph\Pathwise\Indexing\ChecksumIndexer;

beforeEach(function () {
    $this->checksumDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('checksum_', true);
    mkdir($this->checksumDir, 0755, true);
});

afterEach(function () {
    if (!is_dir($this->checksumDir)) {
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

