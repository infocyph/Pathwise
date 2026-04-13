<?php

namespace Infocyph\Pathwise\Queue;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

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
     * @return array{processed: int, failed: int}
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
            'pending' => $decoded['pending'] ?? [],
            'processing' => $decoded['processing'] ?? [],
            'failed' => $decoded['failed'] ?? [],
        ];
    }

    private function writeQueueData(array $data): void
    {
        FlysystemHelper::write($this->queueFilePath, (string) json_encode($data, JSON_PRETTY_PRINT));
    }
}
