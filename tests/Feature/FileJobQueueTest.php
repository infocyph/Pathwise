<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\QueueException;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\Queue\QueueReservation;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->queueFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('queue_', true) . '.json';
});

afterEach(function () {
    foreach (glob($this->queueFile . '.tmp.*') ?: [] as $temporary) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    foreach ([$this->queueFile, $this->queueFile . '.lock'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    FlysystemHelper::reset();
});

test('it rejects malformed jobs instead of dropping them', function () {
    new FileJobQueue($this->queueFile);
    file_put_contents($this->queueFile, json_encode([
        'version' => 1,
        'pending' => [[
            'id' => '',
            'type' => 'x',
            'payload' => [],
            'priority' => 0,
            'createdAt' => time(),
        ]],
        'processing' => [],
        'failed' => [],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => (new FileJobQueue($this->queueFile))->stats())
        ->toThrow(QueueException::class, 'malformed job identifier');
});

test('queue-created local state and stable lock use private permissions', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(true)->toBeTrue();

        return;
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_private_queue_', true);
    $queueFile = $root . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'jobs.json';

    try {
        $queue = new FileJobQueue($queueFile);
        $queue->enqueue('private');

        $directoryMode = fileperms(dirname($queueFile));
        $fileMode = fileperms($queueFile);
        $lockMode = fileperms($queueFile . '.lock');

        expect($directoryMode)->toBeInt()
            ->and($directoryMode & 0777)->toBe(0700)
            ->and($fileMode)->toBeInt()
            ->and($fileMode & 0777)->toBe(0600)
            ->and($lockMode)->toBeInt()
            ->and($lockMode & 0777)->toBe(0600);
    } finally {
        if (is_dir($root)) {
            (new Infocyph\Pathwise\DirectoryManager\DirectoryOperations($root))->delete(true);
        }
    }
});

test('it enforces payload and total job bounds', function () {
    $queue = new FileJobQueue($this->queueFile, maxJobs: 1, maxPayloadBytes: 8);

    expect(fn () => $queue->enqueue('too-large', ['value' => 'payload']))
        ->toThrow(QueueException::class, 'payload exceeds')
        ->and($queue->enqueue('first'))->toStartWith('job_')
        ->and(fn () => $queue->enqueue('second'))->toThrow(QueueException::class, 'job-count');
});

test('it rejects mounted and default-filesystem queue paths', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('queue_mount_', true);
    mkdir($root);
    FlysystemHelper::mount('queue', new Filesystem(new LocalFilesystemAdapter($root)));

    try {
        expect(fn () => new FileJobQueue('queue://jobs.json'))
            ->toThrow(QueueException::class, 'direct-local');

        FlysystemHelper::setDefaultFilesystem(new Filesystem(new LocalFilesystemAdapter($root)));
        expect(fn () => new FileJobQueue('jobs.json'))
            ->toThrow(QueueException::class, 'direct-local');
    } finally {
        FlysystemHelper::reset();
        rmdir($root);
    }
});

test('it processes queued jobs by priority with typed reservations', function () {
    $queue = new FileJobQueue($this->queueFile);
    $order = [];

    $queue->enqueue('low', ['id' => 1], 1);
    $queue->enqueue('high', ['id' => 2], 10);

    $result = $queue->process(function (QueueReservation $reservation) use (&$order): void {
        $order[] = $reservation->type;
    });

    expect($result->processed)->toBe(2)
        ->and($result->failed)->toBe(0)
        ->and($order)->toBe(['high', 'low']);
});

test('it tracks failed jobs', function () {
    $queue = new FileJobQueue($this->queueFile);
    $queue->enqueue('failing-job');

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

test('it exposes an explicit lease lifecycle', function () {
    $queue = new FileJobQueue($this->queueFile, reservationTimeout: 30);
    $jobId = $queue->enqueue('manual', ['key' => 'value'], 7);

    $reservation = $queue->reserve();

    expect($reservation)->toBeInstanceOf(QueueReservation::class)
        ->and($reservation?->id)->toBe($jobId)
        ->and($reservation?->leaseToken)->toMatch('/^lease_[a-f0-9]{32}$/')
        ->and($reservation?->type)->toBe('manual')
        ->and($reservation?->payload)->toBe(['key' => 'value'])
        ->and($reservation?->priority)->toBe(7)
        ->and($reservation?->expiresAt)->toBe(($reservation?->reservedAt ?? 0) + 30)
        ->and($queue->stats())->toMatchArray(['pending' => 0, 'processing' => 1, 'failed' => 0]);

    $renewed = $queue->renew($reservation);
    expect($renewed->leaseToken)->toBe($reservation->leaseToken)
        ->and($renewed->reservedAt)->toBeGreaterThanOrEqual($reservation->reservedAt);

    $queue->release($renewed);
    expect($queue->stats())->toMatchArray(['pending' => 1, 'processing' => 0, 'failed' => 0]);

    $second = $queue->reserve();
    expect($second)->toBeInstanceOf(QueueReservation::class)
        ->and($second?->leaseToken)->not->toBe($reservation->leaseToken);

    $queue->acknowledge($second);
    expect($queue->stats())->toMatchArray(['pending' => 0, 'processing' => 0, 'failed' => 0]);
});

test('an expired or reclaimed lease cannot mutate queue state', function () {
    $queue = new FileJobQueue($this->queueFile, reservationTimeout: 5);
    $queue->enqueue('leased');
    $workerA = $queue->reserve();
    expect($workerA)->toBeInstanceOf(QueueReservation::class);

    $state = json_decode((string) file_get_contents($this->queueFile), true, 512, JSON_THROW_ON_ERROR);
    $state['processing'][0]['reservedAt'] = time() - 10;
    file_put_contents($this->queueFile, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    expect(fn () => $queue->acknowledge($workerA))->toThrow(QueueException::class, 'stale')
        ->and(fn () => $queue->release($workerA))->toThrow(QueueException::class, 'stale')
        ->and(fn () => $queue->renew($workerA))->toThrow(QueueException::class, 'stale')
        ->and(fn () => $queue->fail($workerA, 'late failure'))->toThrow(QueueException::class, 'stale');

    $workerB = $queue->reserve();
    expect($workerB)->toBeInstanceOf(QueueReservation::class)
        ->and($workerB?->id)->toBe($workerA?->id)
        ->and($workerB?->leaseToken)->not->toBe($workerA?->leaseToken);

    expect(fn () => $queue->acknowledge($workerA))->toThrow(QueueException::class, 'stale')
        ->and($queue->stats())->toMatchArray(['pending' => 0, 'processing' => 1, 'failed' => 0]);

    $queue->acknowledge($workerB);
    expect($queue->stats())->toMatchArray(['pending' => 0, 'processing' => 0, 'failed' => 0]);
});

test('it creates opaque identifiers and rejects corrupt queue data', function () {
    $queue = new FileJobQueue($this->queueFile);
    $jobId = $queue->enqueue('opaque');

    expect($jobId)->toMatch('/^job_[a-f0-9]{32}$/');

    file_put_contents($this->queueFile, '{invalid');
    expect(fn () => $queue->stats())->toThrow(QueueException::class, 'invalid JSON');
});

test('it rejects empty truncated and unsupported state instead of guessing recovery', function () {
    $queue = new FileJobQueue($this->queueFile);
    $queue->enqueue('kept');

    file_put_contents($this->queueFile, '');
    expect(fn () => new FileJobQueue($this->queueFile))->toThrow(QueueException::class, 'empty or truncated');

    file_put_contents($this->queueFile, json_encode([
        'version' => 999,
        'pending' => [],
        'processing' => [],
        'failed' => [],
    ], JSON_THROW_ON_ERROR));
    expect(fn () => new FileJobQueue($this->queueFile))->toThrow(QueueException::class, 'version');
});

test('it discards orphan temporary state while preserving the committed queue', function () {
    $queue = new FileJobQueue($this->queueFile);
    $queue->enqueue('committed');

    $orphan = $this->queueFile . '.tmp.state_' . str_repeat('a', 32);
    file_put_contents($orphan, '{uncommitted');

    $reopened = new FileJobQueue($this->queueFile);

    expect(is_file($orphan))->toBeFalse()
        ->and($reopened->stats())->toMatchArray(['pending' => 1, 'processing' => 0, 'failed' => 0]);
});

test('it rejects duplicate job identifiers across queue buckets', function () {
    new FileJobQueue($this->queueFile);
    $jobId = 'job_' . str_repeat('a', 32);
    $job = [
        'id' => $jobId,
        'type' => 'duplicate',
        'payload' => [],
        'priority' => 0,
        'createdAt' => time(),
    ];
    file_put_contents($this->queueFile, json_encode([
        'version' => 1,
        'pending' => [$job],
        'processing' => [],
        'failed' => [[...$job, 'error' => 'failed', 'failedAt' => time()]],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => new FileJobQueue($this->queueFile))
        ->toThrow(QueueException::class, 'duplicate job identifier');
});
