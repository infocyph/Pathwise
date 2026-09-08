<?php

declare(strict_types=1);

use Infocyph\Pathwise\FileManager\FileTransactionJournal;
use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Pathwise\Retention\RetentionManager;
use Infocyph\Pathwise\Utils\FileWatcher;

beforeEach(function (): void {
    $this->subsystemRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_subsystems_', true);
    mkdir($this->subsystemRoot, 0755, true);
});

afterEach(function (): void {
    if (!is_dir($this->subsystemRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->subsystemRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isFile() || $item->isLink()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($this->subsystemRoot);
});

test('retention preview matches apply without mutating during preview', function (): void {
    $paths = [];
    for ($index = 0; $index < 3; $index++) {
        $path = $this->subsystemRoot . DIRECTORY_SEPARATOR . "retention-{$index}.txt";
        file_put_contents($path, (string) $index);
        touch($path, time() - (30 - $index));
        $paths[] = $path;
    }

    $preview = RetentionManager::preview($this->subsystemRoot, keepLast: 2);
    expect($preview->deleted)->toHaveCount(1)
        ->and($preview->kept)->toHaveCount(2)
        ->and(array_filter($paths, 'is_file'))->toHaveCount(3);

    $applied = RetentionManager::apply($this->subsystemRoot, keepLast: 2);
    expect($applied->deleted)->toBe($preview->deleted)
        ->and($applied->kept)->toBe($preview->kept)
        ->and(array_filter($paths, 'is_file'))->toHaveCount(2);
});

test('checksum iteration streams deterministic checksum path records', function (): void {
    file_put_contents($this->subsystemRoot . DIRECTORY_SEPARATOR . 'a.txt', 'A');
    file_put_contents($this->subsystemRoot . DIRECTORY_SEPARATOR . 'b.txt', 'B');

    $iterator = ChecksumIndexer::iterate($this->subsystemRoot);
    expect($iterator)->toBeInstanceOf(Generator::class);

    $records = iterator_to_array($iterator, false);
    expect($records)->toHaveCount(2)
        ->and($records[0])->toHaveKeys(['checksum', 'path'])
        ->and($records[0]['checksum'])->toHaveLength(64);
});

test('watcher rejects ambiguous polling bounds', function (): void {
    expect(fn () => FileWatcher::watch($this->subsystemRoot, static fn (): null => null, durationSeconds: 0))
        ->toThrow(InvalidArgumentException::class, 'duration')
        ->and(fn () => FileWatcher::watch(
            $this->subsystemRoot,
            static fn (): null => null,
            durationSeconds: 1,
            intervalMilliseconds: 9,
        ))->toThrow(InvalidArgumentException::class, 'interval');
});

test('snapshot diffs are ordered deterministically', function (): void {
    $diff = FileWatcher::diff(
        ['z.txt' => ['mtime' => 1, 'size' => 1], 'gone.txt' => ['mtime' => 1, 'size' => 1]],
        ['z.txt' => ['mtime' => 2, 'size' => 1], 'b.txt' => ['mtime' => 1, 'size' => 1], 'a.txt' => ['mtime' => 1, 'size' => 1]],
    );

    expect($diff->created)->toBe(['a.txt', 'b.txt'])
        ->and($diff->modified)->toBe(['z.txt'])
        ->and($diff->deleted)->toBe(['gone.txt']);
});

test('transaction journal restores file content and metadata boundary', function (): void {
    $path = $this->subsystemRoot . DIRECTORY_SEPARATOR . 'transaction.txt';
    file_put_contents($path, 'before');
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($path, 0640);
    }

    $journal = new FileTransactionJournal($path);
    $journal->record($path);
    file_put_contents($path, 'after');
    $journal->rollback();

    expect(file_get_contents($path))->toBe('before');
    if (PHP_OS_FAMILY !== 'Windows') {
        $mode = fileperms($path);
        expect($mode)->toBeInt()->and($mode & 0777)->toBe(0640);
    }
});
