<?php

use Infocyph\Pathwise\Utils\FileWatcher;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->watchDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('watch_dir_', true);
    mkdir($this->watchDir);
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('watch_mount_', true);
    mkdir($this->mountRoot);
    FlysystemHelper::mount('watch', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
});

afterEach(function () {
    FlysystemHelper::reset();

    if (is_dir($this->watchDir)) {
        foreach (glob($this->watchDir . DIRECTORY_SEPARATOR . '*') as $item) {
            if (is_file($item)) {
                unlink($item);
            }
        }
        rmdir($this->watchDir);
    }

    if (is_dir($this->mountRoot)) {
        foreach (glob($this->mountRoot . DIRECTORY_SEPARATOR . '*') as $item) {
            if (is_file($item)) {
                unlink($item);
            }
        }
        rmdir($this->mountRoot);
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
        pcntl_exec(PHP_BINARY, ['-r', 'exit(0);']);
        throw new RuntimeException('Failed to terminate child process.');
    }

    FileWatcher::watch($this->watchDir, function (array $diff) use (&$events) {
        $events[] = $diff;
    }, durationSeconds: 2, intervalMilliseconds: 100);

    pcntl_waitpid($pid, $status);

    expect($events)->not->toBeEmpty();
});

test('it supports snapshots for mounted paths', function () {
    FlysystemHelper::write('watch://a.txt', 'a');
    $snapshotA = FileWatcher::snapshot('watch://');

    usleep(150000);
    FlysystemHelper::write('watch://a.txt', 'a-modified');
    FlysystemHelper::write('watch://b.txt', 'b');
    $snapshotB = FileWatcher::snapshot('watch://');
    $diff = FileWatcher::diff($snapshotA, $snapshotB);

    expect($diff['created'])->toContain('watch://b.txt')
        ->and($diff['modified'])->toContain('watch://a.txt');
});
