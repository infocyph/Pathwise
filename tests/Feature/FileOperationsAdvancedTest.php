<?php

use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Security\PolicyEngine;

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

