<?php

use Infocyph\Pathwise\Utils\FileWatcher;

beforeEach(function () {
    $this->watchDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('watch_dir_', true);
    mkdir($this->watchDir);
});

afterEach(function () {
    if (is_dir($this->watchDir)) {
        foreach (glob($this->watchDir . DIRECTORY_SEPARATOR . '*') as $item) {
            if (is_file($item)) {
                unlink($item);
            }
        }
        rmdir($this->watchDir);
    }
});

test('it creates snapshot and diff reports', function () {
    $fileA = $this->watchDir . DIRECTORY_SEPARATOR . 'a.txt';
    file_put_contents($fileA, 'a');

    $snapshotA = FileWatcher::snapshot($this->watchDir);
    usleep(150000);
    file_put_contents($fileA, 'a-modified');
    $fileB = $this->watchDir . DIRECTORY_SEPARATOR . 'b.txt';
    file_put_contents($fileB, 'b');

    $snapshotB = FileWatcher::snapshot($this->watchDir);
    $diff = FileWatcher::diff($snapshotA, $snapshotB);

    expect($diff['created'])->toContain($fileB)
        ->and($diff['modified'])->toContain($fileA);
});

test('it watches directory changes with callback', function () {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('pcntl not available in this environment.');
    }

    $events = [];
    $targetFile = $this->watchDir . DIRECTORY_SEPARATOR . 'watch.txt';

    $pid = pcntl_fork();

    if ($pid === 0) {
        usleep(300000);
        file_put_contents($targetFile, 'changed');
        exit(0);
    }

    FileWatcher::watch($this->watchDir, function (array $diff) use (&$events) {
        $events[] = $diff;
    }, durationSeconds: 2, intervalMilliseconds: 100);

    pcntl_waitpid($pid, $status);

    expect($events)->not->toBeEmpty();
});
