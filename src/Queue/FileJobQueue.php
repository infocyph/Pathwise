<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Queue;

use Infocyph\Pathwise\Results\QueueProcessResult;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * @phpstan-type QueueJob array{
 *     id: string,
 *     type: string,
 *     payload: array<string, mixed>,
 *     priority: int,
 *     createdAt: int,
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
    public function __construct(private string $queueFilePath)
    {
        $directory = dirname($this->queueFilePath);
        if (!FlysystemHelper::directoryExists($directory)) {
            FlysystemHelper::createDirectory($directory);
        }
        if ($this->isLocalQueuePath()) {
            $this->initializeLocalQueue();
        } elseif (!FlysystemHelper::fileExists($this->queueFilePath)) {
            FlysystemHelper::write($this->queueFilePath, $this->encodeQueueData($this->emptyQueueData()));
        }
    }

    /**
     * Add a job to the queue.
     *
     * @param string $type The job type.
     * @param array<string, mixed> $payload The job payload data.
     * @param int $priority The job priority (higher is more important).
     * @return string The job ID.
     */
    public function enqueue(string $type, array $payload = [], int $priority = 0): string
    {
        if (trim($type) === '') {
            throw new InvalidArgumentException('Queue job type must not be empty.');
        }

        $jobId = 'job_' . bin2hex(random_bytes(16));

        return $this->mutateQueueData(static function (array $data) use ($jobId, $type, $payload, $priority): array {
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
     * @return QueueJob|null
     */
    private function claimNextJob(): ?array
    {
        return $this->mutateQueueData(static function (array $data): array {
            if ($data['pending'] === []) {
                return [$data, null];
            }

            $job = array_shift($data['pending']);
            $data['processing'][] = $job;

            return [$data, $job];
        });
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
            throw new RuntimeException("Queue file contains invalid JSON: {$this->queueFilePath}", 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Queue file does not contain an object: {$this->queueFilePath}");
        }

        return [
            'pending' => $this->normalizeJobList($decoded['pending'] ?? []),
            'processing' => $this->normalizeJobList($decoded['processing'] ?? []),
            'failed' => $this->normalizeJobList($decoded['failed'] ?? []),
        ];
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
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function initializeLocalQueue(): void
    {
        $stream = fopen($this->queueFilePath, 'c+b');
        if (!is_resource($stream)) {
            throw new RuntimeException("Unable to initialize queue file: {$this->queueFilePath}");
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException("Unable to lock queue file: {$this->queueFilePath}");
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

    /**
     * @template T
     * @param callable(QueueState): array{0: QueueState, 1: T} $mutation
     * @return T
     */
    private function mutateQueueData(callable $mutation): mixed
    {
        if (!$this->isLocalQueuePath()) {
            [$data, $result] = $mutation($this->readQueueData());
            $this->writeQueueData($data);

            return $result;
        }

        $stream = fopen($this->queueFilePath, 'c+b');
        if (!is_resource($stream)) {
            throw new RuntimeException("Unable to open queue file: {$this->queueFilePath}");
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException("Unable to lock queue file: {$this->queueFilePath}");
            }

            rewind($stream);
            $content = stream_get_contents($stream);
            [$data, $result] = $mutation($this->decodeQueueData(is_string($content) ? $content : ''));
            $encoded = $this->encodeQueueData($data);

            rewind($stream);
            if (!ftruncate($stream, 0)) {
                throw new RuntimeException("Unable to truncate queue file: {$this->queueFilePath}");
            }
            $this->writeFully($stream, $encoded);
            if (!fflush($stream)) {
                throw new RuntimeException("Unable to flush queue file: {$this->queueFilePath}");
            }

            return $result;
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /**
     * @return QueueJob|null
     */
    private function normalizeJob(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $id = $value['id'] ?? null;
        $type = $value['type'] ?? null;
        $payload = $this->normalizePayload($value['payload'] ?? []);
        $priority = $value['priority'] ?? 0;
        $createdAt = $value['createdAt'] ?? time();
        if (!is_string($id) || !is_string($type)) {
            return null;
        }

        if ((!is_int($priority) && !is_numeric($priority)) || (!is_int($createdAt) && !is_numeric($createdAt))) {
            return null;
        }

        $job = [
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'priority' => (int) $priority,
            'createdAt' => (int) $createdAt,
        ];

        $error = $value['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $job['error'] = $error;
        }

        $failedAt = $value['failedAt'] ?? null;
        if (is_int($failedAt) || is_numeric($failedAt)) {
            $job['failedAt'] = (int) $failedAt;
        }

        return $job;
    }

    /**
     * @return list<QueueJob>
     */
    private function normalizeJobList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $jobs = [];
        foreach ($value as $rawJob) {
            $job = $this->normalizeJob($rawJob);
            if ($job === null) {
                continue;
            }

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
            return [];
        }

        $payload = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $payload[$key] = $item;
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

        if (!$this->isLocalQueuePath()) {
            return $this->decodeQueueData(FlysystemHelper::read($this->queueFilePath));
        }

        $stream = fopen($this->queueFilePath, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException("Unable to open queue file: {$this->queueFilePath}");
        }

        try {
            if (!flock($stream, LOCK_SH)) {
                throw new RuntimeException("Unable to lock queue file: {$this->queueFilePath}");
            }

            $content = stream_get_contents($stream);

            return $this->decodeQueueData(is_string($content) ? $content : '');
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function writeFully(mixed $stream, string $contents): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Invalid queue stream.');
        }

        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException("Unable to write queue file: {$this->queueFilePath}");
            }
            $offset += $written;
        }
    }

    /**
     * @param QueueState $data
     */
    private function writeQueueData(array $data): void
    {
        FlysystemHelper::write($this->queueFilePath, $this->encodeQueueData($data));
    }
}
