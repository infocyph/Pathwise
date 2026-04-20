<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Queue;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

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
        if (!FlysystemHelper::fileExists($this->queueFilePath)) {
            FlysystemHelper::write($this->queueFilePath, (string) json_encode(['pending' => [], 'processing' => [], 'failed' => []], JSON_PRETTY_PRINT));
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
        $jobId = uniqid('job_', true);
        $data = $this->readQueueData();
        $data['pending'][] = [
            'id' => $jobId,
            'type' => $type,
            'payload' => $payload,
            'priority' => $priority,
            'createdAt' => time(),
        ];

        usort($data['pending'], static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
        $this->writeQueueData($data);

        return $jobId;
    }

    /**
     * Process jobs from the queue.
     *
     * @param callable(QueueJob): void $handler Callback to process each job.
     * @param int $maxJobs Maximum number of jobs to process (0 for unlimited).
     * @return array{processed: int, failed: int} Array with processed and failed counts.
     */
    public function process(callable $handler, int $maxJobs = 0): array
    {
        $processed = 0;
        $failed = 0;

        while (true) {
            $data = $this->readQueueData();
            if ($data['pending'] === []) {
                break;
            }
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }

            $job = array_shift($data['pending']);
            $data['processing'][] = $job;
            $this->writeQueueData($data);

            try {
                $handler($job);
                $processed++;
            } catch (\Throwable $e) {
                $job['error'] = $e->getMessage();
                $job['failedAt'] = time();
                $failed++;
            }

            $data = $this->readQueueData();
            $data['processing'] = array_values(array_filter(
                $data['processing'],
                static fn(array $processingJob): bool => $processingJob['id'] !== $job['id'],
            ));

            if (isset($job['error'])) {
                $data['failed'][] = $job;
            }
            $this->writeQueueData($data);
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
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
            return ['pending' => [], 'processing' => [], 'failed' => []];
        }

        $content = FlysystemHelper::read($this->queueFilePath);
        if ($content === '') {
            return ['pending' => [], 'processing' => [], 'failed' => []];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return ['pending' => [], 'processing' => [], 'failed' => []];
        }

        return [
            'pending' => $this->normalizeJobList($decoded['pending'] ?? []),
            'processing' => $this->normalizeJobList($decoded['processing'] ?? []),
            'failed' => $this->normalizeJobList($decoded['failed'] ?? []),
        ];
    }

    /**
     * @param QueueState $data
     */
    private function writeQueueData(array $data): void
    {
        FlysystemHelper::write($this->queueFilePath, (string) json_encode($data, JSON_PRETTY_PRINT));
    }
}
