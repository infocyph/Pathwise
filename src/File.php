<?php

namespace Infocyph\Pathwise;

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\FileManager\SafeFileReader;
use Infocyph\Pathwise\FileManager\SafeFileWriter;
use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Queue\FileJobQueue;
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

final class File
{
    public function __construct(private string $path)
    {
        $this->path = PathHelper::normalize($path);
    }

    public static function at(string $path): self
    {
        return new self($path);
    }

    public static function audit(string $logFilePath): AuditTrail
    {
        return new AuditTrail($logFilePath);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function createFilesystem(array $config): FilesystemOperator
    {
        return StorageFactory::createFilesystem($config);
    }

    /**
     * @return array{linked: array, skipped: array}
     */
    public static function deduplicate(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::deduplicateWithHardLinks($directory, $algorithm);
    }

    /**
     * @return array{created: array, modified: array, deleted: array}
     */
    public static function diffSnapshots(array $previousSnapshot, array $currentSnapshot): array
    {
        return FileWatcher::diff($previousSnapshot, $currentSnapshot);
    }

    public static function download(): DownloadProcessor
    {
        return new DownloadProcessor();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function duplicates(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::findDuplicates($directory, $algorithm);
    }

    public static function from(string $path): self
    {
        return self::at($path);
    }

    /**
     * @return array<string, array<int, string>> checksum => paths
     */
    public static function index(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::buildIndex($directory, $algorithm);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function mountStorage(string $name, array $config): FilesystemOperator
    {
        return StorageFactory::mount($name, $config);
    }

    /**
     * @param array<string, array<string, mixed>> $mounts
     */
    public static function mountStorages(array $mounts): void
    {
        StorageFactory::mountMany($mounts);
    }

    public static function policy(): PolicyEngine
    {
        return new PolicyEngine();
    }

    public static function queue(string $queueFilePath): FileJobQueue
    {
        return new FileJobQueue($queueFilePath);
    }

    /**
     * @return array{deleted: array, kept: array}
     */
    public static function retain(
        string $directory,
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime',
    ): array {
        return RetentionManager::apply($directory, $keepLast, $maxAgeDays, $sortBy);
    }

    /**
     * @return array<string, array{mtime: int, size: int}>
     */
    public static function snapshot(string $path, bool $recursive = true): array
    {
        return FileWatcher::snapshot($path, $recursive);
    }

    public static function upload(): UploadProcessor
    {
        return new UploadProcessor();
    }

    /**
     * @return array<string, array{mtime: int, size: int}>
     */
    public static function watch(
        string $path,
        callable $onChange,
        int $durationSeconds = 5,
        int $intervalMilliseconds = 500,
        bool $recursive = true,
    ): array {
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

    public function metadata(bool $humanReadableSize = false): ?array
    {
        return MetadataHelper::getAllMetadata($this->path, $humanReadableSize);
    }

    public function mimeType(): ?string
    {
        return MetadataHelper::getMimeType($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function reader(string $mode = 'r', bool $exclusiveLock = false): SafeFileReader
    {
        return new SafeFileReader($this->path, $mode, $exclusiveLock);
    }

    public function writer(bool $append = false): SafeFileWriter
    {
        return new SafeFileWriter($this->path, $append);
    }
}
