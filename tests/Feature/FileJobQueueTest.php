<?php

declare(strict_types=1);

use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->queueFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('queue_', true) . '.json';
});

afterEach(function () {
    if (is_file($this->queueFile)) {
        unlink($this->queueFile);
    }
    FlysystemHelper::reset();
});

test('it rejects malformed jobs instead of dropping them', function () {
    new FileJobQueue($this->queueFile);
    file_put_contents($this->queueFile, json_encode([
        'pending' => [['id' => '', 'type' => 'x', 'payload' => [], 'priority' => 0, 'createdAt' => time()]],
        'processing' => [],
        'failed' => [],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => (new FileJobQueue($this->queueFile))->stats())
        ->toThrow(RuntimeException::class, 'malformed job');
});

test('it enforces payload and total job bounds', function () {
    $queue = new FileJobQueue($this->queueFile, maxJobs: 1, maxPayloadBytes: 8);

    expect(fn () => $queue->enqueue('too-large', ['value' => 'payload']))
        ->toThrow(RuntimeException::class, 'payload exceeds')
        ->and($queue->enqueue('first'))->toStartWith('job_')
        ->and(fn () => $queue->enqueue('second'))->toThrow(RuntimeException::class, 'job-count');
});

test('it rejects mounted and default-filesystem queue paths', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('queue_mount_', true);
    mkdir($root);
    FlysystemHelper::mount('queue', new Filesystem(new LocalFilesystemAdapter($root)));

    try {
        expect(fn () => new FileJobQueue('queue://jobs.json'))
            ->toThrow(RuntimeException::class, 'direct-local');

        FlysystemHelper::setDefaultFilesystem(new Filesystem(new LocalFilesystemAdapter($root)));
        expect(fn () => new FileJobQueue('jobs.json'))
            ->toThrow(RuntimeException::class, 'direct-local');
    } finally {
        FlysystemHelper::reset();
        rmdir($root);
    }
});

test('it processes queued jobs by priority', function () {
    $queue = new FileJobQueue($this->queueFile);
    $order = [];

    $queue->enqueue('low', ['id' => 1], 1);
    $queue->enqueue('high', ['id' => 2], 10);

    $result = $queue->process(function (array $job) use (&$order): void {
        $order[] = $job['type'];
    });

    expect($result->processed)->toBe(2)
        ->and($result->failed)->toBe(0)
        ->and($order)->toBe(['high', 'low']);
});

test('it tracks failed jobs', function () {
    $queue = new FileJobQueue($this->queueFile);
    $queue->enqueue('failing-job', [], 0);

    $result = $queue->process(function (): void {
        throw new RuntimeException('boom');
    });
    $stats = $queue->stats();

    expect($result->processed)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($stats['failed'])->toBe(1);
});

test('it limits processing attempts even when jobs fail', function () {
    $queue = new FileJobQueue($this->queueFile);
    $queue->enqueue('first');
    $queue->enqueue('second');

    $result = $queue->process(static function (): void {
        throw new RuntimeException('boom');
    }, 1);
    $stats = $queue->stats();

    expect($result->processed)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($stats)->toMatchArray(['pending' => 1, 'processing' => 0, 'failed' => 1]);
});

test('it creates opaque job identifiers and rejects corrupt queue data', function () {
    $queue = new FileJobQueue($this->queueFile);
    $jobId = $queue->enqueue('opaque');

    expect($jobId)->toMatch('/^job_[a-f0-9]{32}$/');

    file_put_contents($this->queueFile, '{invalid');
    expect(fn() => $queue->stats())->toThrow(RuntimeException::class, 'invalid JSON');
});
