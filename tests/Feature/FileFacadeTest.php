<?php

use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\Utils\FlysystemHelper;

beforeEach(function () {
    FlysystemHelper::reset();
    StorageFactory::clearDrivers();
    $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_file_facade_', true);
    mkdir($this->workspace, 0755, true);
});

afterEach(function () {
    FlysystemHelper::reset();
    StorageFactory::clearDrivers();

    if (!is_dir($this->workspace)) {
        return;
    }

    deleteDirectory($this->workspace);
});

test('it provides path-bound file accessors', function () {
    $filePath = $this->workspace . DIRECTORY_SEPARATOR . 'sample.txt';
    $entry = PathwiseFacade::at($filePath);

    $entry->file()->create("line-1\n");

    $writer = $entry->writer(true);
    $writer->line('line-2');
    $writer->close();

    $content = $entry->file()->read();
    $lines = iterator_to_array($entry->reader()->line());

    expect($entry->exists())->toBeTrue()
        ->and($entry->path())->toBe($filePath)
        ->and($content)->toContain("line-1\n")
        ->and($content)->toContain('line-2')
        ->and(trim((string) ($lines[0] ?? '')))->toBe('line-1')
        ->and(trim((string) ($lines[1] ?? '')))->toBe('line-2')
        ->and($entry->metadata())->toBeArray();
});

test('it provides path-bound directory and compression accessors', function () {
    $sourceDir = $this->workspace . DIRECTORY_SEPARATOR . 'source';
    mkdir($sourceDir, 0755, true);
    file_put_contents($sourceDir . DIRECTORY_SEPARATOR . 'a.txt', 'A');

    $zipPath = $this->workspace . DIRECTORY_SEPARATOR . 'archive.zip';
    $extractDir = $this->workspace . DIRECTORY_SEPARATOR . 'extract';

    PathwiseFacade::at($sourceDir)->directory()->create();
    PathwiseFacade::at($zipPath)->compression(true)->compress($sourceDir)->save();
    PathwiseFacade::at($zipPath)->compression()->decompress($extractDir)->save();

    expect(FlysystemHelper::fileExists($zipPath))->toBeTrue()
        ->and(FlysystemHelper::fileExists($extractDir . DIRECTORY_SEPARATOR . 'a.txt'))->toBeTrue()
        ->and(FlysystemHelper::read($extractDir . DIRECTORY_SEPARATOR . 'a.txt'))->toBe('A');
});

test('it provides static gateways for processors policy storage and ops tooling', function () {
    $root = $this->workspace . DIRECTORY_SEPARATOR . 'storage';
    mkdir($root, 0755, true);

    PathwiseFacade::mountStorage('facade', [
        'driver' => 'local',
        'root' => $root,
    ]);

    FlysystemHelper::write('facade://data/file.txt', 'hello');

    $upload = PathwiseFacade::upload();
    $download = PathwiseFacade::download();
    $policy = PathwiseFacade::policy()->allow('*', '*');

    $queueFile = $this->workspace . DIRECTORY_SEPARATOR . 'queue' . DIRECTORY_SEPARATOR . 'jobs.json';
    $queue = PathwiseFacade::queue($queueFile);
    $queue->enqueue('example', ['id' => 1], 10);
    $stats = $queue->stats();

    $auditFile = $this->workspace . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'audit.jsonl';
    $audit = PathwiseFacade::audit($auditFile);
    $audit->log('facade.test', ['ok' => true]);

    $watchPath = $this->workspace . DIRECTORY_SEPARATOR . 'watch.txt';
    file_put_contents($watchPath, 'v1');
    $snapshotA = PathwiseFacade::snapshot($watchPath);
    file_put_contents($watchPath, 'v22');
    $snapshotB = PathwiseFacade::snapshot($watchPath);
    $diff = PathwiseFacade::diffSnapshots($snapshotA, $snapshotB);

    $dupDir = $this->workspace . DIRECTORY_SEPARATOR . 'dups';
    mkdir($dupDir, 0755, true);
    file_put_contents($dupDir . DIRECTORY_SEPARATOR . 'a.txt', 'dup');
    file_put_contents($dupDir . DIRECTORY_SEPARATOR . 'b.txt', 'dup');
    $index = PathwiseFacade::index($dupDir);
    $duplicates = PathwiseFacade::duplicates($dupDir);

    $retention = PathwiseFacade::retain($this->workspace . DIRECTORY_SEPARATOR . 'empty-retention');

    expect($upload)->toBeInstanceOf(\Infocyph\Pathwise\StreamHandler\UploadProcessor::class)
        ->and($download)->toBeInstanceOf(\Infocyph\Pathwise\StreamHandler\DownloadProcessor::class)
        ->and($policy->isAllowed('read', 'anything'))->toBeTrue()
        ->and(FlysystemHelper::read('facade://data/file.txt'))->toBe('hello')
        ->and($stats['pending'])->toBe(1)
        ->and(FlysystemHelper::fileExists($auditFile))->toBeTrue()
        ->and($diff['modified'])->toContain($watchPath)
        ->and($index)->not->toBeEmpty()
        ->and($duplicates)->not->toBeEmpty()
        ->and($retention)->toBe(['deleted' => [], 'kept' => []]);

    FlysystemHelper::unmount('facade');
});
