<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Queue;

use Infocyph\Pathwise\Exceptions\QueueException;
use Infocyph\Pathwise\Results\QueueProcessResult;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use InvalidArgumentException;
use JsonException;

/**
 * @phpstan-type QueueJob array{
 *     id: string,
 *     type: string,
 *     payload: array<string, mixed>,
 *     priority: int,
 *     createdAt: int,
 *     reservedAt?: int,
 *     leaseToken?: string,
 *     error?: string,
 *     failedAt?: int
 * }
 * @phpstan-type QueueState array{
 *     version: int,
 *     pending: list<QueueJob>,
 *     processing: list<QueueJob>,
 *     failed: list<QueueJob>
 * }
 */
final readonly class FileJobQueue
{
    private const int ERROR_MESSAGE_BYTES = 4096;

    private const int STATE_VERSION = 1;

    private FileQueueStateStore $stateStore;

    public function __construct(
        private string $queueFilePath,
        private int $reservationTimeout = 300,
        private int $maxJobs = 10_000,
        int $maxQueueBytes = 16_777_216,
        private int $maxPayloadBytes = 1_048_576,
    ) {
        if (!$this->isLocalQueuePath()) {
            throw new QueueException('FileJobQueue requires a direct-local filesystem path.');
        }
        if (
            $reservationTimeout < 1
            || $maxJobs < 1
            || $maxQueueBytes < 1
            || $maxPayloadBytes < 1
        ) {
            throw new InvalidArgumentException('Queue limits and reservation timeout must be positive integers.');
        }

        $this->stateStore = new FileQueueStateStore($this->queueFilePath, $maxQueueBytes);
        $this->stateStore->initialize($this->encodeQueueData($this->emptyQueueData()));
        $this->decodeQueueData($this->stateStore->read());
    }

    public function acknowledge(QueueReservation $reservation): void
    {
        $this->mutateQueueData(function (array $data) use ($reservation): array {
            $index = $this->processingIndexForLease($data, $reservation);
            array_splice($data['processing'], $index, 1);

            return [$data, null];
        });
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public function enqueue(string $type, array $payload = [], int $priority = 0): string
    {
        if (trim($type) === '') {
            throw new InvalidArgumentException('Queue job type must not be empty.');
        }
        if (array_any(array_keys($payload), static fn(int|string $key): bool => !is_string($key))) {
            throw new InvalidArgumentException('Queue payload keys must be strings.');
        }
        $payload = $this->normalizePayload($payload);
        $jobId = $this->newOpaqueId('job');

        return $this->mutateQueueData(function (array $data) use ($jobId, $type, $payload, $priority): array {
            if ($this->jobCount($data) >= $this->maxJobs) {
                throw new QueueException('Queue exceeds the configured job-count limit.');
            }

            $data['pending'][] = [
                'id' => $jobId,
                'type' => $type,
                'payload' => $payload,
                'priority' => $priority,
                'createdAt' => time(),
            ];
            $this->sortPending($data);

            return [$data, $jobId];
        });
    }

    public function fail(QueueReservation $reservation, \Throwable|string $failure): void
    {
        $message = $failure instanceof \Throwable ? $failure->getMessage() : $failure;
        $message = trim($message) === '' ? 'Queue job failed.' : $message;
        $message = substr($message, 0, self::ERROR_MESSAGE_BYTES);

        $this->mutateQueueData(function (array $data) use ($reservation, $message): array {
            $index = $this->processingIndexForLease($data, $reservation);
            $job = $data['processing'][$index];
            array_splice($data['processing'], $index, 1);

            unset($job['reservedAt'], $job['leaseToken']);
            $job['error'] = $message;
            $job['failedAt'] = time();
            $data['failed'][] = $job;

            return [$data, null];
        });
    }

    /**
     * @param callable(QueueReservation): void $handler
     */
    public function process(callable $handler, int $maxJobs = 0): QueueProcessResult
    {
        if ($maxJobs < 0) {
            throw new InvalidArgumentException('maxJobs must be greater than or equal to zero.');
        }

        $processed = 0;
        $failed = 0;
        $attempted = 0;

        while ($maxJobs === 0 || $attempted < $maxJobs) {
            $reservation = $this->reserve();
            if (!$reservation instanceof QueueReservation) {
                break;
            }
            $attempted++;

            try {
                $handler($reservation);
            } catch (\Throwable $exception) {
                $this->fail($reservation, $exception);
                $failed++;

                continue;
            }

            $this->acknowledge($reservation);
            $processed++;
        }

        return new QueueProcessResult($processed, $failed);
    }

    public function release(QueueReservation $reservation): void
    {
        $this->mutateQueueData(function (array $data) use ($reservation): array {
            $index = $this->processingIndexForLease($data, $reservation);
            $job = $data['processing'][$index];
            array_splice($data['processing'], $index, 1);

            unset($job['reservedAt'], $job['leaseToken']);
            $data['pending'][] = $job;
            $this->sortPending($data);

            return [$data, null];
        });
    }

    public function renew(QueueReservation $reservation): QueueReservation
    {
        return $this->mutateQueueData(function (array $data) use ($reservation): array {
            $index = $this->processingIndexForLease($data, $reservation);
            $job = $data['processing'][$index];
            $job['reservedAt'] = time();
            $data['processing'][$index] = $job;

            return [$data, $this->reservationFromJob($job)];
        });
    }

    public function reserve(): ?QueueReservation
    {
        return $this->mutateQueueData($this->reserveFromQueueState(...));
    }

    /**
     * @return array{pending: int, processing: int, failed: int, file: string}
     */
    public function stats(): array
    {
        $data = $this->decodeQueueData($this->stateStore->read());

        return [
            'pending' => count($data['pending']),
            'processing' => count($data['processing']),
            'failed' => count($data['failed']),
            'file' => PathHelper::normalize($this->queueFilePath),
        ];
    }

    /** @param array<array-key, mixed> $value */
    private function assertNoFailureState(array $value): void
    {
        if (isset($value['error']) || isset($value['failedAt'])) {
            throw new QueueException('Pending queue job contains failure state.');
        }
    }

    /** @param array<array-key, mixed> $value */
    private function assertNoReservationState(array $value): void
    {
        if (isset($value['reservedAt']) || isset($value['leaseToken'])) {
            throw new QueueException('Non-processing queue job contains reservation state.');
        }
    }

    /** @param QueueState $data */
    private function assertUniqueJobIds(array $data): void
    {
        $seen = [];
        foreach (['pending', 'processing', 'failed'] as $bucket) {
            foreach ($data[$bucket] as $job) {
                if (isset($seen[$job['id']])) {
                    throw new QueueException("Queue contains duplicate job identifier: {$job['id']}");
                }
                $seen[$job['id']] = true;
            }
        }
    }

    /** @return QueueState */
    private function decodeQueueData(string $content): array
    {
        if ($content === '') {
            throw new QueueException("Queue file is empty or truncated: {$this->queueFilePath}");
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException("Queue file contains invalid JSON: {$this->queueFilePath}", 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new QueueException("Queue file does not contain an object: {$this->queueFilePath}");
        }
        if (($decoded['version'] ?? null) !== self::STATE_VERSION) {
            throw new QueueException('Queue state version is missing or unsupported.');
        }
        foreach (['pending', 'processing', 'failed'] as $bucket) {
            if (!array_key_exists($bucket, $decoded)) {
                throw new QueueException("Queue state is missing the {$bucket} bucket.");
            }
        }

        $state = [
            'version' => self::STATE_VERSION,
            'pending' => $this->normalizeJobList($decoded['pending'], 'pending'),
            'processing' => $this->normalizeJobList($decoded['processing'], 'processing'),
            'failed' => $this->normalizeJobList($decoded['failed'], 'failed'),
        ];
        if ($this->jobCount($state) > $this->maxJobs) {
            throw new QueueException('Queue exceeds the configured job-count limit.');
        }
        $this->assertUniqueJobIds($state);

        return $state;
    }

    /** @return QueueState */
    private function emptyQueueData(): array
    {
        return [
            'version' => self::STATE_VERSION,
            'pending' => [],
            'processing' => [],
            'failed' => [],
        ];
    }

    /** @param QueueState $data */
    private function encodeQueueData(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new QueueException('Queue data cannot be encoded as JSON.', 0, $exception);
        }
    }

    private function isLocalQueuePath(): bool
    {
        return !PathHelper::hasScheme($this->queueFilePath)
            && (PathHelper::isAbsolute($this->queueFilePath) || !FlysystemHelper::hasDefaultFilesystem());
    }

    /** @param QueueState $data */
    private function jobCount(array $data): int
    {
        return count($data['pending']) + count($data['processing']) + count($data['failed']);
    }

    /**
     * @param QueueJob $job
     * @return QueueJob
     */
    private function leaseJob(array $job): array
    {
        $job['reservedAt'] = time();
        $job['leaseToken'] = $this->newOpaqueId('lease');

        return $job;
    }

    /**
     * @template T
     * @param callable(QueueState): array{0: QueueState, 1: T} $mutation
     * @return T
     */
    private function mutateQueueData(callable $mutation): mixed
    {
        return $this->stateStore->mutate(function (string $content) use ($mutation): array {
            [$data, $result] = $mutation($this->decodeQueueData($content));

            return [$this->encodeQueueData($data), $result];
        });
    }

    private function newOpaqueId(string $prefix): string
    {
        try {
            return $prefix . '_' . bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            throw new QueueException("Unable to generate {$prefix} identifier.", 0, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @return QueueJob
     */
    private function normalizeBaseJob(array $value): array
    {
        $id = $value['id'] ?? null;
        if (!is_string($id) || preg_match('/^job_[a-f0-9]{32}$/D', $id) !== 1) {
            throw new QueueException('Queue contains a malformed job identifier.');
        }

        $type = $value['type'] ?? null;
        $priority = $value['priority'] ?? null;
        $createdAt = $value['createdAt'] ?? null;
        if (!is_string($type) || trim($type) === '' || !is_int($priority) || !is_int($createdAt) || $createdAt < 0) {
            throw new QueueException('Queue contains a malformed job.');
        }

        return [
            'id' => $id,
            'type' => $type,
            'payload' => $this->normalizePayload($value['payload'] ?? null),
            'priority' => $priority,
            'createdAt' => $createdAt,
        ];
    }

    /**
     * @param QueueJob $job
     * @param array<array-key, mixed> $value
     * @return QueueJob
     */
    private function normalizeFailedJob(array $job, array $value): array
    {
        $error = $value['error'] ?? null;
        $failedAt = $value['failedAt'] ?? null;
        if (!is_string($error) || trim($error) === '' || !is_int($failedAt) || $failedAt < 1) {
            throw new QueueException('Queue contains malformed failure state.');
        }
        if (strlen($error) > self::ERROR_MESSAGE_BYTES) {
            throw new QueueException('Queue failure message exceeds the configured state limit.');
        }

        $job['error'] = $error;
        $job['failedAt'] = $failedAt;

        return $job;
    }

    /** @return QueueJob */
    private function normalizeJob(mixed $value, string $bucket): array
    {
        if (!is_array($value)) {
            throw new QueueException('Queue contains a malformed job.');
        }

        $job = $this->normalizeBaseJob($value);
        if ($bucket === 'processing') {
            return $this->normalizeProcessingJob($job, $value);
        }

        $this->assertNoReservationState($value);
        if ($bucket === 'failed') {
            return $this->normalizeFailedJob($job, $value);
        }

        $this->assertNoFailureState($value);

        return $job;
    }

    /** @return list<QueueJob> */
    private function normalizeJobList(mixed $value, string $bucket): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new QueueException("Queue {$bucket} bucket must be a list.");
        }

        return array_map(fn(mixed $job): array => $this->normalizeJob($job, $bucket), $value);
    }

    /** @return array<string, mixed> */
    private function normalizePayload(mixed $value): array
    {
        if (!is_array($value)) {
            throw new QueueException('Queue payload must be an object.');
        }

        $payload = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new QueueException('Queue payload keys must be strings.');
            }
            $payload[$key] = $item;
        }

        try {
            $payloadBytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new QueueException('Queue payload cannot be encoded as JSON.', 0, $exception);
        }
        if ($payloadBytes > $this->maxPayloadBytes) {
            throw new QueueException('Queue payload exceeds the configured size limit.');
        }

        return $payload;
    }

    /**
     * @param QueueJob $job
     * @param array<array-key, mixed> $value
     * @return QueueJob
     */
    private function normalizeProcessingJob(array $job, array $value): array
    {
        $reservedAt = $value['reservedAt'] ?? null;
        $leaseToken = $value['leaseToken'] ?? null;
        if (!is_int($reservedAt) || $reservedAt < 1) {
            throw new QueueException('Queue contains a malformed reservation timestamp.');
        }
        if (!is_string($leaseToken) || preg_match('/^lease_[a-f0-9]{32}$/D', $leaseToken) !== 1) {
            throw new QueueException('Queue contains a malformed reservation lease token.');
        }
        if (isset($value['error']) || isset($value['failedAt'])) {
            throw new QueueException('Processing queue job contains failure state.');
        }

        $job['reservedAt'] = $reservedAt;
        $job['leaseToken'] = $leaseToken;

        return $job;
    }

    /** @param QueueState $data */
    private function processingIndexForLease(array $data, QueueReservation $reservation): int
    {
        foreach ($data['processing'] as $index => $job) {
            if ($job['id'] !== $reservation->id) {
                continue;
            }
            if (($job['leaseToken'] ?? null) !== $reservation->leaseToken) {
                throw new QueueException('Queue reservation lease is stale or no longer owned.');
            }
            if (($job['reservedAt'] ?? 0) <= time() - $this->reservationTimeout) {
                throw new QueueException('Queue reservation lease is stale or no longer owned.');
            }

            return $index;
        }

        throw new QueueException('Queue reservation lease is stale or no longer owned.');
    }

    /**
     * @param QueueState $data
     * @return QueueState
     */
    private function reclaimStaleReservations(array $data): array
    {
        $cutoff = time() - $this->reservationTimeout;
        $active = [];

        foreach ($data['processing'] as $job) {
            if (($job['reservedAt'] ?? 0) > $cutoff) {
                $active[] = $job;

                continue;
            }

            unset($job['reservedAt'], $job['leaseToken']);
            $data['pending'][] = $job;
        }

        $data['processing'] = $active;
        $this->sortPending($data);

        return $data;
    }

    /** @param QueueJob $job */
    private function reservationFromJob(array $job): QueueReservation
    {
        $reservedAt = $job['reservedAt'] ?? null;
        $leaseToken = $job['leaseToken'] ?? null;
        if (!is_int($reservedAt) || !is_string($leaseToken)) {
            throw new QueueException('Processing queue job is missing lease ownership state.');
        }

        return new QueueReservation(
            id: $job['id'],
            leaseToken: $leaseToken,
            type: $job['type'],
            payload: $job['payload'],
            priority: $job['priority'],
            createdAt: $job['createdAt'],
            reservedAt: $reservedAt,
            expiresAt: $reservedAt + $this->reservationTimeout,
        );
    }

    /**
     * @param QueueState $data
     * @return array{0: QueueState, 1: QueueReservation|null}
     */
    private function reserveFromQueueState(array $data): array
    {
        $data = $this->reclaimStaleReservations($data);
        if ($data['pending'] === []) {
            return [$data, null];
        }

        $job = $this->leaseJob($data['pending'][0]);
        array_shift($data['pending']);
        $data['processing'][] = $job;

        return [$data, $this->reservationFromJob($job)];
    }

    /** @param QueueState $data */
    private function sortPending(array &$data): void
    {
        usort($data['pending'], static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
    }
}
