<?php

use Infocyph\Pathwise\Retention\RetentionManager;

beforeEach(function () {
    $this->retentionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('retention_', true);
    mkdir($this->retentionDir, 0755, true);
});

afterEach(function () {
    if (!is_dir($this->retentionDir)) {
        return;
    }

    foreach (scandir($this->retentionDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $this->retentionDir . DIRECTORY_SEPARATOR . $item;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($this->retentionDir);
});

test('it keeps only latest files when keepLast is set', function () {
    $files = [];
    for ($i = 0; $i < 3; $i++) {
        $path = $this->retentionDir . DIRECTORY_SEPARATOR . "file_{$i}.txt";
        file_put_contents($path, "v{$i}");
        touch($path, time() - (10 - $i));
        $files[] = $path;
    }

    $report = RetentionManager::apply($this->retentionDir, keepLast: 2);

    expect($report['kept'])->toHaveCount(2)
        ->and($report['deleted'])->toHaveCount(1);
});

test('it deletes files older than maxAgeDays', function () {
    $old = $this->retentionDir . DIRECTORY_SEPARATOR . 'old.txt';
    $new = $this->retentionDir . DIRECTORY_SEPARATOR . 'new.txt';
    file_put_contents($old, 'old');
    file_put_contents($new, 'new');
    touch($old, time() - (3 * 86400));
    touch($new, time());

    $report = RetentionManager::apply($this->retentionDir, keepLast: null, maxAgeDays: 1);

    expect($report['deleted'])->toContain($old)
        ->and($report['kept'])->toContain($new)
        ->and(is_file($old))->toBeFalse();
});

