<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

use Infocyph\Pathwise\Exceptions\AuditException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

final readonly class LocalJsonlAuditSink implements AuditSink
{
    public string $path;

    public function __construct(string $path)
    {
        if (!FlysystemHelper::isLocalPath($path)) {
            throw new UnsupportedStorageOperationException(
                'Append-based JSONL auditing requires a local path; provide a partitioned or callback sink for remote storage.',
            );
        }

        $this->path = PathHelper::normalize($path);
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new AuditException("Unable to create audit directory: {$directory}");
            }
            if (!chmod($directory, 0700)) {
                throw new AuditException("Unable to secure audit directory: {$directory}");
            }
        }
    }

    public function write(array $record): void
    {
        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (\JsonException $exception) {
            throw new AuditException('Unable to encode audit record.', 0, $exception);
        }

        $stream = fopen($this->path, 'ab');
        if (!is_resource($stream)) {
            throw new AuditException("Unable to open audit record file: {$this->path}.");
        }

        $locked = false;

        try {
            if (!chmod($this->path, 0600)) {
                throw new AuditException("Unable to secure audit record file: {$this->path}.");
            }
            if (!flock($stream, LOCK_EX)) {
                throw new AuditException("Unable to lock audit record file: {$this->path}.");
            }
            $locked = true;

            self::writeAll($stream, $line, $this->path);
            if (!fflush($stream)) {
                throw new AuditException("Unable to flush audit record file: {$this->path}.");
            }
            if (function_exists('fsync') && !fsync($stream)) {
                throw new AuditException("Unable to synchronize audit record file: {$this->path}.");
            }
        } finally {
            if ($locked) {
                flock($stream, LOCK_UN);
            }
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private static function writeAll(mixed $stream, string $contents, string $path): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if (!is_int($written) || $written < 1) {
                throw new AuditException("Unable to append audit record to {$path}.");
            }
            $offset += $written;
        }
    }
}
