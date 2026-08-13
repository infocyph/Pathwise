<?php

declare(strict_types=1);

use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Exceptions\AuditException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\Observability\CallbackAuditSink;
use Infocyph\Pathwise\Observability\PartitionedAuditSink;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

test('it writes JSON lines audit records', function () {
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('audit_', true) . '.jsonl';
    $audit = new AuditTrail($logFile);
    $audit->log('create', ['path' => '/tmp/file.txt']);
    $audit->log('delete', ['path' => '/tmp/file.txt']);

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect($lines)->toBeArray()->toHaveCount(2);

    $first = json_decode((string) $lines[0], true);
    $second = json_decode((string) $lines[1], true);

    expect($first)->toBeArray()
        ->and($first['operation'] ?? null)->toBe('create')
        ->and($second['operation'] ?? null)->toBe('delete');

    if (is_file($logFile)) {
        unlink($logFile);
    }
});

test('it fails without corrupting the log when context cannot be encoded', function () {
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('audit_', true) . '.jsonl';
    $audit = new AuditTrail($logFile);
    $audit->log('valid');
    $stream = fopen('php://temp', 'rb');

    try {
        expect(fn() => $audit->log('invalid', ['stream' => $stream]))->toThrow(AuditException::class)
            ->and(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->toHaveCount(1);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (is_file($logFile)) {
            unlink($logFile);
        }
    }
});

test('it rejects append-based JSONL auditing on mounted storage', function () {
    expect(fn () => new AuditTrail('audit-remote://events.jsonl'))
        ->toThrow(UnsupportedStorageOperationException::class, 'requires a local path');
});

test('it sends records to callback sinks', function () {
    $records = [];
    $audit = new AuditTrail(new CallbackAuditSink(function (array $record) use (&$records): void {
        $records[] = $record;
    }));

    $audit->log('copy', ['source' => 'a', 'destination' => 'b']);

    expect($audit->getLogFilePath())->toBeNull()
        ->and($records)->toHaveCount(1)
        ->and($records[0]['operation'])->toBe('copy');
});

test('it writes one immutable object per event to mounted storage', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_audit_partition_', true);
    mkdir($root, 0755, true);
    FlysystemHelper::mount('audit-partition', new Filesystem(new LocalFilesystemAdapter($root)));

    try {
        $audit = new AuditTrail(new PartitionedAuditSink('audit-partition://events'));
        $audit->log('create', ['path' => 'one']);
        $audit->log('delete', ['path' => 'two']);
        $objects = iterator_to_array(FlysystemHelper::listContentsListing('audit-partition://events', true), false);
        $files = array_values(array_filter($objects, static fn ($entry): bool => $entry->isFile()));

        expect($files)->toHaveCount(2);
    } finally {
        FlysystemHelper::unmount('audit-partition');
        (new Infocyph\Pathwise\DirectoryManager\DirectoryOperations($root))->delete(true);
    }
});
