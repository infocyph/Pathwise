<?php

use Infocyph\Pathwise\Observability\AuditTrail;

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

