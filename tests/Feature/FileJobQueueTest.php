<?php

declare(strict_types=1);

use Infocyph\Pathwise\Queue\FileJobQueue;

beforeEach(function () {
    $this->queueFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('queue_', true) . '.json';
});

afterEach(function () {
    if (is_file($this->queueFile)) {
        unlink($this->queueFile);
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

    expect($result['processed'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and($order)->toBe(['high', 'low']);
});

test('it tracks failed jobs', function () {
    $queue = new FileJobQueue($this->queueFile);
    $queue->enqueue('failing-job', [], 0);

    $result = $queue->process(function (): void {
        throw new RuntimeException('boom');
    });
    $stats = $queue->stats();

    expect($result['processed'])->toBe(0)
        ->and($result['failed'])->toBe(1)
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

    expect($result)->toBe(['processed' => 0, 'failed' => 1])
        ->and($stats)->toMatchArray(['pending' => 1, 'processing' => 0, 'failed' => 1]);
});

test('it creates opaque job identifiers and rejects corrupt queue data', function () {
    $queue = new FileJobQueue($this->queueFile);
    $jobId = $queue->enqueue('opaque');

    expect($jobId)->toMatch('/^job_[a-f0-9]{32}$/');

    file_put_contents($this->queueFile, '{invalid');
    expect(fn() => $queue->stats())->toThrow(RuntimeException::class, 'invalid JSON');
});
