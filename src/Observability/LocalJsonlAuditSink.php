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
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new AuditException("Unable to create audit directory: {$directory}");
        }
    }

    public function write(array $record): void
    {
        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (\JsonException $exception) {
            throw new AuditException('Unable to encode audit record.', 0, $exception);
        }
        $written = file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        if ($written !== strlen($line)) {
            throw new AuditException("Unable to append audit record to {$this->path}.");
        }
    }
}
