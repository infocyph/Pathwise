<?php

use Infocyph\Pathwise\Retention\RetentionManager;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->retentionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('retention_', true);
    mkdir($this->retentionDir, 0755, true);
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('retention_mount_', true);
    mkdir($this->mountRoot, 0755, true);
    FlysystemHelper::mount('ret', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
});

afterEach(function () {
    FlysystemHelper::reset();

    if (!is_dir($this->retentionDir)) {
        if (is_dir($this->mountRoot)) {
            rmdir($this->mountRoot);
        }
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

test('it applies retention rules on mounted paths', function () {
    FlysystemHelper::write('ret://a.txt', 'a');
    usleep(100000);
    FlysystemHelper::write('ret://b.txt', 'b');
    usleep(100000);
    FlysystemHelper::write('ret://c.txt', 'c');

    $report = RetentionManager::apply('ret://', keepLast: 2);

    expect($report['kept'])->toHaveCount(2)
        ->and($report['deleted'])->toHaveCount(1);
});
