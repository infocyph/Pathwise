<?php

declare(strict_types=1);

namespace Infocyph\Pathwise;

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\FileManager\SafeFileReader;
use Infocyph\Pathwise\FileManager\SafeFileWriter;
use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Pathwise\Observability\AuditSink;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\Results\DeduplicationResult;
use Infocyph\Pathwise\Results\RetentionResult;
use Infocyph\Pathwise\Results\SnapshotDiff;
use Infocyph\Pathwise\Results\WatchResult;
use Infocyph\Pathwise\Retention\RetentionManager;
use Infocyph\Pathwise\Security\PolicyEngine;
use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\Utils\FileWatcher;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\MetadataHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\FilesystemOperator;

/**
 * Stateless convenience facade for common Pathwise operations.
 *
 * Persistent storage topology belongs to Storage\StorageContext rather than
 * process-global facade registration.
 *
 * @phpstan-type SnapshotEntry array{mtime: int, size: int}
 * @phpstan-type SnapshotMap array<string, SnapshotEntry>
 */
final class PathwiseFacade
{
    public function __construct(private string $path)
    {
        $this->path = PathHelper::normalize($path);
    }

    public static function at(string $path): self
    {
        return new self($path);
    }

    public static function audit(string|AuditSink $sink): AuditTrail
    {
        return new AuditTrail($sink);
    }

    /** @param array<string, mixed> $config */
    public static function createFilesystem(array $config): FilesystemOperator
    {
        return StorageFactory::createFilesystem($config);
    }

    public static function deduplicate(string $directory, string $algorithm = 'sha256'): DeduplicationResult
    {
        return ChecksumIndexer::deduplicateWithHardLinks($directory, $algorithm);
    }

    /**
     * @param SnapshotMap $previousSnapshot
     * @param SnapshotMap $currentSnapshot
     */
    public static function diffSnapshots(array $previousSnapshot, array $currentSnapshot): SnapshotDiff
    {
        return FileWatcher::diff($previousSnapshot, $currentSnapshot);
    }

    public static function download(): DownloadProcessor
    {
        return new DownloadProcessor();
    }

    /** @return array<string, list<string>> */
    public static function duplicates(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::findDuplicates($directory, $algorithm);
    }

    /** @return array<string, list<string>> */
    public static function index(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::buildIndex($directory, $algorithm);
    }

    public static function policy(): PolicyEngine
    {
        return new PolicyEngine();
    }

    public static function queue(string $queueFilePath): FileJobQueue
    {
        return new FileJobQueue($queueFilePath);
    }

    public static function retain(
        string $directory,
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime',
    ): RetentionResult {
        return RetentionManager::apply($directory, $keepLast, $maxAgeDays, $sortBy);
    }

    /** @return SnapshotMap */
    public static function snapshot(string $path, bool $recursive = true): array
    {
        return FileWatcher::snapshot($path, $recursive);
    }

    public static function upload(): UploadProcessor
    {
        return new UploadProcessor();
    }

    public static function watch(
        string $path,
        callable $onChange,
        int $durationSeconds = 5,
        int $intervalMilliseconds = 500,
        bool $recursive = true,
    ): WatchResult {
        return FileWatcher::watch($path, $onChange, $durationSeconds, $intervalMilliseconds, $recursive);
    }

    public function compression(bool $create = false): FileCompression
    {
        return new FileCompression($this->path, $create);
    }

    public function directory(): DirectoryOperations
    {
        return new DirectoryOperations($this->path);
    }

    public function exists(): bool
    {
        return FlysystemHelper::has($this->path);
    }

    public function file(): FileOperations
    {
        return new FileOperations($this->path);
    }

    /** @return array<string, mixed>|null */
    public function metadata(bool $humanReadableSize = false): ?array
    {
        return self::normalizeStringMap(MetadataHelper::getAllMetadata($this->path, $humanReadableSize));
    }

    public function mimeType(): ?string
    {
        return MetadataHelper::getMimeType($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function reader(string $mode = 'r', ?int $lockType = null): SafeFileReader
    {
        return new SafeFileReader($this->path, $mode, $lockType);
    }

    public function writer(bool $append = false): SafeFileWriter
    {
        return new SafeFileWriter($this->path, $append);
    }

    /**
     * @param array<mixed, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private static function normalizeStringMap(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
