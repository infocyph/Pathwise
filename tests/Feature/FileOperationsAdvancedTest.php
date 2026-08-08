<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\Exceptions\TransactionStateException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Security\PolicyEngine;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('file_ops_adv_', true) . '.txt';
    $this->auditPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('audit_adv_', true) . '.jsonl';
    $this->fileOperations = new FileOperations($this->filePath);
});

afterEach(function () {
    if (is_file($this->filePath)) {
        unlink($this->filePath);
    }
    if (is_file($this->auditPath)) {
        unlink($this->auditPath);
    }
});

test('it enforces policy rules for operations', function () {
    $this->fileOperations->create('content');
    $policy = (new PolicyEngine())->deny('delete', '*');
    $this->fileOperations->setPolicyEngine($policy);

    expect(fn () => $this->fileOperations->delete())->toThrow(PolicyViolationException::class);
});

test('it records audit trail entries', function () {
    $this->fileOperations
        ->setAuditTrail(new AuditTrail($this->auditPath))
        ->create('one')
        ->update('two');

    $lines = file($this->auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect($lines)->toBeArray()->toHaveCount(2);
});

test('it rolls back file changes when transaction fails', function () {
    $this->fileOperations->create('original');

    try {
        $this->fileOperations->transaction(function (FileOperations $ops): void {
            $ops->update('updated');
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    expect(file_get_contents($this->filePath))->toBe('original');
});

test('it removes files created or appended from a missing state during rollback', function (string $operation) {
    try {
        $this->fileOperations->transaction(function (FileOperations $ops) use ($operation): void {
            $operation === 'create' ? $ops->create('new') : $ops->append('new');
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    expect(file_exists($this->filePath))->toBeFalse();
})->with(['create', 'append']);

test('it restores the original object path and both files after rename rollback', function () {
    $renamedPath = $this->filePath . '.renamed';
    $this->fileOperations->create('original');

    try {
        $this->fileOperations->transaction(function (FileOperations $ops) use ($renamedPath): void {
            $ops->rename($renamedPath)->update('changed');
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    expect($this->fileOperations->read())->toBe('original')
        ->and(file_exists($renamedPath))->toBeFalse();
});

test('it restores overwritten copy destinations and deleted large files', function () {
    $destination = $this->filePath . '.copy';
    $large = str_repeat('0123456789abcdef', 128 * 1024);
    $this->fileOperations->create($large);
    file_put_contents($destination, 'destination-before');

    try {
        $this->fileOperations->transaction(function (FileOperations $ops) use ($destination): void {
            $ops->copy($destination)->delete();
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    expect(file_get_contents($this->filePath))->toBe($large)
        ->and(file_get_contents($destination))->toBe('destination-before');

    unlink($destination);
});

test('it rejects nested and invalid transaction states', function () {
    expect(fn () => $this->fileOperations->commitTransaction())
        ->toThrow(TransactionStateException::class, 'without an active transaction');

    $this->fileOperations->beginTransaction();
    expect(fn () => $this->fileOperations->beginTransaction())
        ->toThrow(TransactionStateException::class, 'Nested transactions');
    $this->fileOperations->rollbackTransaction();

    expect(fn () => $this->fileOperations->rollbackTransaction())
        ->toThrow(TransactionStateException::class, 'without an active transaction');
});

test('it rejects transactions and native local operations on mounted storage', function () {
    FlysystemHelper::mount('transaction-remote', new Filesystem(new LocalFilesystemAdapter($this->tempDir ?? sys_get_temp_dir())));
    $mounted = new FileOperations('transaction-remote://file.txt');

    try {
        expect(fn () => $mounted->beginTransaction())
            ->toThrow(UnsupportedStorageOperationException::class)
            ->and(fn () => $mounted->append('x'))
            ->toThrow(UnsupportedStorageOperationException::class);
    } finally {
        FlysystemHelper::unmount('transaction-remote');
    }
});
