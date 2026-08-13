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
 *     error?: string,
 *     failedAt?: int
 * }
 * @phpstan-type QueueState array{
 *     pending: list<QueueJob>,
 *     processing: list<QueueJob>,
 *     failed: list<QueueJob>
 * }
 */
final readonly class FileJobQueue
{
    public function __construct(
        private string $queueFilePath,
        private int $reservationTimeout = 300,
        private int $maxJobs = 10_000,
        private int $maxQueueBytes = 16_777_216,
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
        $directory = dirname($this->queueFilePath);
        if (!FlysystemHelper::directoryExists($directory)) {
            FlysystemHelper::createDirectory($directory);
        }
        $this->initializeLocalQueue();
    }

    /**
     * Add a job to the queue.
     *
     * @param string $type The job type.
     * @param array<array-key, mixed> $payload The job payload data.
     * @param int $priority The job priority (higher is more important).
     * @return string The job ID.
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

        try {
            $payloadBytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new QueueException('Queue payload cannot be encoded as JSON.', 0, $exception);
        }
        if ($payloadBytes > $this->maxPayloadBytes) {
            throw new QueueException('Queue payload exceeds the configured size limit.');
        }

        $jobId = 'job_' . bin2hex(random_bytes(16));

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

            usort($data['pending'], static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

            return [$data, $jobId];
        });
    }

    /**
     * Process jobs from the queue.
     *
     * @param callable(QueueJob): void $handler Callback to process each job.
     * @param int $maxJobs Maximum number of jobs to process (0 for unlimited).
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
            $job = $this->claimNextJob();
            if ($job === null) {
                break;
            }
            $attempted++;

            try {
                $handler($job);
                $processed++;
            } catch (\Throwable $e) {
                $job['error'] = substr($e->getMessage(), 0, 4096);
                $job['failedAt'] = time();
                $failed++;
            }

            $this->completeJob($job);
        }

        return new QueueProcessResult($processed, $failed);
    }

    /**
     * Get queue statistics.
     *
     * @return array{pending: int, processing: int, failed: int, file: string}
     */
    public function stats(): array
    {
        $data = $this->readQueueData();

        return [
            'pending' => count($data['pending']),
            'processing' => count($data['processing']),
            'failed' => count($data['failed']),
            'file' => PathHelper::normalize($this->queueFilePath),
        ];
    }

    /**
     * @param QueueState $data
     * @return array{0: QueueState, 1: QueueJob|null}
     */
    private function claimFromQueueState(array $data): array
    {
        $data = $this->reclaimStaleReservations($data);
        if ($data['pending'] === []) {
            return [$data, null];
        }

        $job = array_shift($data['pending']);
        $job['reservedAt'] = time();
        $data['processing'][] = $job;

        return [$data, $job];
    }

    /**
     * @return QueueJob|null
     */
    private function claimNextJob(): ?array
    {
        return $this->mutateQueueData($this->claimFromQueueState(...));
    }

    /**
     * @param QueueJob $job
     */
    private function completeJob(array $job): void
    {
        $this->mutateQueueData(static function (array $data) use ($job): array {
            foreach ($data['processing'] as $index => $processingJob) {
                if ($processingJob['id'] !== $job['id']) {
                    continue;
                }

                array_splice($data['processing'], $index, 1);

                break;
            }

            unset($job['reservedAt']);
            if (isset($job['error'])) {
                $data['failed'][] = $job;
            }

            return [$data, null];
        });
    }

    /**
     * @return QueueState
     */
    private function decodeQueueData(string $content): array
    {
        if ($content === '') {
            return $this->emptyQueueData();
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException("Queue file contains invalid JSON: {$this->queueFilePath}", 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new QueueException("Queue file does not contain an object: {$this->queueFilePath}");
        }

        $state = [
            'pending' => $this->normalizeJobList($decoded['pending'] ?? []),
            'processing' => $this->normalizeJobList($decoded['processing'] ?? []),
            'failed' => $this->normalizeJobList($decoded['failed'] ?? []),
        ];
        if ($this->jobCount($state) > $this->maxJobs) {
            throw new QueueException('Queue exceeds the configured job-count limit.');
        }

        return $state;
    }

    /**
     * @return QueueState
     */
    private function emptyQueueData(): array
    {
        return ['pending' => [], 'processing' => [], 'failed' => []];
    }

    /**
     * @param QueueState $data
     */
    private function encodeQueueData(array $data): string
    {
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new QueueException('Queue data cannot be encoded as JSON.', 0, $exception);
        }
        if (strlen($encoded) > $this->maxQueueBytes) {
            throw new QueueException('Queue exceeds the configured byte-size limit.');
        }

        return $encoded;
    }

    private function initializeLocalQueue(): void
    {
        $stream = fopen($this->queueFilePath, 'c+b');
        if (!is_resource($stream)) {
            throw new QueueException("Unable to initialize queue file: {$this->queueFilePath}");
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new QueueException("Unable to lock queue file: {$this->queueFilePath}");
            }

            $metadata = fstat($stream);
            if (!is_array($metadata) || $metadata['size'] !== 0) {
                return;
            }

            $this->writeFully($stream, $this->encodeQueueData($this->emptyQueueData()));
            fflush($stream);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
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
     * @template T
     * @param callable(QueueState): array{0: QueueState, 1: T} $mutation
     * @return T
     */
    private function mutateQueueData(callable $mutation): mixed
    {
        $stream = fopen($this->queueFilePath, 'c+b');
        if (!is_resource($stream)) {
            throw new QueueException("Unable to open queue file: {$this->queueFilePath}");
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new QueueException("Unable to lock queue file: {$this->queueFilePath}");
            }

            rewind($stream);
            $content = stream_get_contents($stream);
            [$data, $result] = $mutation($this->decodeQueueData(is_string($content) ? $content : ''));
            $encoded = $this->encodeQueueData($data);

            rewind($stream);
            if (!ftruncate($stream, 0)) {
                throw new QueueException("Unable to truncate queue file: {$this->queueFilePath}");
            }
            $this->writeFully($stream, $encoded);
            if (!fflush($stream)) {
                throw new QueueException("Unable to flush queue file: {$this->queueFilePath}");
            }

            return $result;
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /** @return QueueJob */
    private function normalizeJob(mixed $value): array
    {
        if (!is_array($value)) {
            throw new QueueException('Queue contains a malformed job.');
        }

        $id = $value['id'] ?? null;
        $type = $value['type'] ?? null;
        $payload = $this->normalizePayload($value['payload'] ?? null);
        $priority = $value['priority'] ?? null;
        $createdAt = $value['createdAt'] ?? null;
        if (!is_string($id) || trim($id) === '' || !is_string($type) || trim($type) === '') {
            throw new QueueException('Queue contains a malformed job.');
        }

        if (!is_int($priority) || !is_int($createdAt) || $createdAt < 0) {
            throw new QueueException('Queue contains a malformed job.');
        }

        $job = [
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'priority' => $priority,
            'createdAt' => $createdAt,
        ];

        $error = $value['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $job['error'] = $error;
        }

        $failedAt = $value['failedAt'] ?? null;
        if ($failedAt !== null) {
            if (!is_int($failedAt) || $failedAt < 0) {
                throw new QueueException('Queue contains a malformed failure timestamp.');
            }
            $job['failedAt'] = $failedAt;
        }
        $reservedAt = $value['reservedAt'] ?? null;
        if ($reservedAt !== null) {
            if (!is_int($reservedAt) || $reservedAt < 0) {
                throw new QueueException('Queue contains a malformed reservation timestamp.');
            }
            $job['reservedAt'] = $reservedAt;
        }

        return $job;
    }

    /**
     * @return list<QueueJob>
     */
    private function normalizeJobList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new QueueException('Queue job list must be an array.');
        }

        $jobs = [];
        foreach ($value as $rawJob) {
            $job = $this->normalizeJob($rawJob);
            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * @return array<string, mixed>
     */
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
     * @return QueueState
     */
    private function readQueueData(): array
    {
        if (!FlysystemHelper::fileExists($this->queueFilePath)) {
            return $this->emptyQueueData();
        }

        $stream = fopen($this->queueFilePath, 'rb');
        if (!is_resource($stream)) {
            throw new QueueException("Unable to open queue file: {$this->queueFilePath}");
        }

        try {
            if (!flock($stream, LOCK_SH)) {
                throw new QueueException("Unable to lock queue file: {$this->queueFilePath}");
            }

            $content = stream_get_contents($stream);
            if (is_string($content) && strlen($content) > $this->maxQueueBytes) {
                throw new QueueException('Queue exceeds the configured byte-size limit.');
            }

            return $this->decodeQueueData(is_string($content) ? $content : '');
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
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
            $reservedAt = $job['reservedAt'] ?? 0;
            if ($reservedAt > $cutoff) {
                $active[] = $job;

                continue;
            }

            unset($job['reservedAt']);
            $data['pending'][] = $job;
        }
        $data['processing'] = $active;
        usort($data['pending'], static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $data;
    }

    private function writeFully(mixed $stream, string $contents): void
    {
        if (!is_resource($stream)) {
            throw new QueueException('Invalid queue stream.');
        }

        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if (!is_int($written) || $written < 1) {
                throw new QueueException("Unable to write queue file: {$this->queueFilePath}");
            }
            $offset += $written;
        }
    }
}
