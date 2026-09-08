<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Queue;

use DirectoryIterator;
use Infocyph\Pathwise\Exceptions\QueueException;
use InvalidArgumentException;

/**
 * Internal direct-local persistence boundary for FileJobQueue.
 */
final readonly class FileQueueStateStore
{
    public function __construct(
        private string $queueFilePath,
        private int $maxQueueBytes,
    ) {
        if ($maxQueueBytes < 1) {
            throw new InvalidArgumentException('Queue byte-size limit must be a positive integer.');
        }

        $this->ensurePrivateQueueDirectory();
    }

    public function initialize(string $initialState): void
    {
        $this->withExclusiveLock(function () use ($initialState): void {
            $this->cleanupOrphanTemps();
            if (!file_exists($this->queueFilePath)) {
                $this->persistState($initialState);

                return;
            }

            $this->readStateUnlocked();
        });
    }

    /**
     * @template T
     * @param callable(string): array{0: string, 1: T} $mutation
     * @return T
     */
    public function mutate(callable $mutation): mixed
    {
        return $this->withExclusiveLock(function () use ($mutation): mixed {
            [$state, $result] = $mutation($this->readStateUnlocked());
            $this->persistState($state);

            return $result;
        });
    }

    public function read(): string
    {
        return $this->withSharedLock(fn(): string => $this->readStateUnlocked());
    }

    private function cleanupOrphanTemps(): void
    {
        $directory = dirname($this->queueFilePath);
        $prefix = basename($this->queueFilePath) . '.tmp.';

        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || !str_starts_with($entry->getFilename(), $prefix)) {
                continue;
            }
            if ($entry->isLink()) {
                throw new QueueException("Queue temporary path is unexpectedly a symbolic link: {$entry->getPathname()}");
            }
            if (!$entry->isFile()) {
                continue;
            }
            if (!unlink($entry->getPathname())) {
                throw new QueueException("Unable to remove orphan queue temporary file: {$entry->getPathname()}");
            }
        }
    }

    private function ensurePrivateQueueDirectory(): void
    {
        $directory = dirname($this->queueFilePath);
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new QueueException("Unable to create queue directory: {$directory}");
        }
        if (!chmod($directory, 0700)) {
            throw new QueueException("Unable to secure queue directory: {$directory}");
        }
    }

    private function lockFilePath(): string
    {
        return $this->queueFilePath . '.lock';
    }

    private function newTempPath(): string
    {
        try {
            return $this->queueFilePath . '.tmp.state_' . bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            throw new QueueException('Unable to generate queue temporary-file identifier.', 0, $exception);
        }
    }

    /** @return resource */
    private function openLockStream(): mixed
    {
        $lockPath = $this->lockFilePath();
        if (is_link($lockPath)) {
            throw new QueueException("Queue lock path must not be a symbolic link: {$lockPath}");
        }

        $stream = fopen($lockPath, 'c+b');
        if (!is_resource($stream)) {
            throw new QueueException("Unable to open queue lock file: {$lockPath}");
        }
        if (!chmod($lockPath, 0600)) {
            fclose($stream);

            throw new QueueException("Unable to secure queue lock file: {$lockPath}");
        }

        return $stream;
    }

    private function persistState(string $state): void
    {
        if (strlen($state) > $this->maxQueueBytes) {
            throw new QueueException('Queue exceeds the configured byte-size limit.');
        }
        if (is_link($this->queueFilePath)) {
            throw new QueueException("Queue state path must not be a symbolic link: {$this->queueFilePath}");
        }
        if (file_exists($this->queueFilePath) && !is_file($this->queueFilePath)) {
            throw new QueueException("Queue state path is not a regular file: {$this->queueFilePath}");
        }

        $tempPath = $this->newTempPath();
        $stream = fopen($tempPath, 'x+b');
        if (!is_resource($stream)) {
            throw new QueueException("Unable to create queue temporary file: {$tempPath}");
        }

        try {
            if (!chmod($tempPath, 0600)) {
                throw new QueueException("Unable to secure queue temporary file: {$tempPath}");
            }
            $this->writeFully($stream, $state);
            if (!fflush($stream) || !fsync($stream)) {
                throw new QueueException("Unable to durably flush queue temporary file: {$tempPath}");
            }
        } finally {
            fclose($stream);
        }

        try {
            if (!rename($tempPath, $this->queueFilePath)) {
                throw new QueueException("Unable to atomically replace queue state: {$this->queueFilePath}");
            }
            if (!chmod($this->queueFilePath, 0600)) {
                throw new QueueException("Unable to secure queue state file: {$this->queueFilePath}");
            }
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function readStateUnlocked(): string
    {
        if (!file_exists($this->queueFilePath)) {
            throw new QueueException("Queue state file is missing: {$this->queueFilePath}");
        }
        if (is_link($this->queueFilePath) || !is_file($this->queueFilePath)) {
            throw new QueueException("Queue state path is not a regular file: {$this->queueFilePath}");
        }

        clearstatcache(true, $this->queueFilePath);
        $size = filesize($this->queueFilePath);
        if (!is_int($size)) {
            throw new QueueException("Unable to inspect queue state file: {$this->queueFilePath}");
        }
        if ($size < 1) {
            throw new QueueException("Queue file is empty or truncated: {$this->queueFilePath}");
        }
        if ($size > $this->maxQueueBytes) {
            throw new QueueException('Queue exceeds the configured byte-size limit.');
        }

        $content = file_get_contents($this->queueFilePath);
        if (!is_string($content) || strlen($content) !== $size) {
            throw new QueueException("Unable to read complete queue state: {$this->queueFilePath}");
        }

        return $content;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withExclusiveLock(callable $operation): mixed
    {
        $stream = $this->openLockStream();

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new QueueException("Unable to acquire queue lock: {$this->lockFilePath()}");
            }

            return $operation();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withSharedLock(callable $operation): mixed
    {
        $stream = $this->openLockStream();

        try {
            if (!flock($stream, LOCK_SH)) {
                throw new QueueException("Unable to acquire queue lock: {$this->lockFilePath()}");
            }

            return $operation();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
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
