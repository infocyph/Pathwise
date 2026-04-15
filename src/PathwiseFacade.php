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

final class PathwiseFacade
{
    /**
     * Constructor to initialize the file path.
     *
     * @param string $path The path to the file or directory.
     */
    public function __construct(private string $path)
    {
        $this->path = PathHelper::normalize($path);
    }

    /**
     * Create a new instance at the given path.
     *
     * @param string $path The path to the file or directory.
     * @return self A new facade instance.
     */
    public static function at(string $path): self
    {
        return new self($path);
    }

    /**
     * Create an audit trail logger.
     *
     * @param string $logFilePath The path to the log file.
     * @return AuditTrail The audit trail instance.
     */
    public static function audit(string $logFilePath): AuditTrail
    {
        return new AuditTrail($logFilePath);
    }

    /**
     * Create a Flysystem filesystem from configuration.
     *
     * @param array<string, mixed> $config The filesystem configuration.
     * @return FilesystemOperator The created filesystem.
     */
    public static function createFilesystem(array $config): FilesystemOperator
    {
        return StorageFactory::createFilesystem($config);
    }

    /**
     * Deduplicate files in a directory using hard links.
     *
     * @param string $directory The directory to deduplicate.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return array{linked: array, skipped: array} Array with linked and skipped file paths.
     */
    public static function deduplicate(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::deduplicateWithHardLinks($directory, $algorithm);
    }

    /**
     * Compare two snapshots and return the differences.
     *
     * @param array $previousSnapshot The previous snapshot data.
     * @param array $currentSnapshot The current snapshot data.
     * @return array{created: array, modified: array, deleted: array} The diff report.
     */
    public static function diffSnapshots(array $previousSnapshot, array $currentSnapshot): array
    {
        return FileWatcher::diff($previousSnapshot, $currentSnapshot);
    }

    /**
     * Create a download processor for secure file downloads.
     *
     * @return DownloadProcessor The download processor instance.
     */
    public static function download(): DownloadProcessor
    {
        return new DownloadProcessor();
    }

    /**
     * Find duplicate files in a directory.
     *
     * @param string $directory The directory to search for duplicates.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return array<string, array<int, string>> Array mapping checksum to duplicate file paths.
     */
    public static function duplicates(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::findDuplicates($directory, $algorithm);
    }

    /**
     * Alias for at() - create a new instance at the given path.
     *
     * @param string $path The path to the file or directory.
     * @return self A new facade instance.
     */
    public static function from(string $path): self
    {
        return self::at($path);
    }

    /**
     * Build a checksum index for all files in a directory.
     *
     * @param string $directory The directory to index.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return array<string, array<int, string>> Array mapping checksum to file paths.
     */
    public static function index(string $directory, string $algorithm = 'sha256'): array
    {
        return ChecksumIndexer::buildIndex($directory, $algorithm);
    }

    /**
     * Create and mount a filesystem under a name.
     *
     * @param string $name The mount name.
     * @param array<string, mixed> $config The filesystem configuration.
     * @return FilesystemOperator The created filesystem.
     */
    public static function mountStorage(string $name, array $config): FilesystemOperator
    {
        return StorageFactory::mount($name, $config);
    }

    /**
     * Mount multiple filesystems at once.
     *
     * @param array<string, array<string, mixed>> $mounts Array of mount name => config pairs.
     */
    public static function mountStorages(array $mounts): void
    {
        StorageFactory::mountMany($mounts);
    }

    /**
     * Create a policy engine for access control.
     *
     * @return PolicyEngine The policy engine instance.
     */
    public static function policy(): PolicyEngine
    {
        return new PolicyEngine();
    }

    /**
     * Create a file-based job queue.
     *
     * @param string $queueFilePath The path to the queue file.
     * @return FileJobQueue The job queue instance.
     */
    public static function queue(string $queueFilePath): FileJobQueue
    {
        return new FileJobQueue($queueFilePath);
    }

    /**
     * Apply retention rules to a directory.
     *
     * @param string $directory The directory to apply retention rules to.
     * @param int|null $keepLast Number of most recent files to keep (null for unlimited).
     * @param int|null $maxAgeDays Maximum age of files in days (null for unlimited).
     * @param string $sortBy Field to sort by ('mtime' or 'ctime').
     * @return array{deleted: array, kept: array} Array with deleted and kept file paths.
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
     * Build a snapshot map for a file or directory.
     *
     * @param string $path The path to snapshot.
     * @param bool $recursive Whether to include subdirectories recursively.
     * @return array<string, array{mtime: int, size: int}> The snapshot map.
     */
    public static function snapshot(string $path, bool $recursive = true): array
    {
        return FileWatcher::snapshot($path, $recursive);
    }

    /**
     * Create an upload processor for secure file uploads.
     *
     * @return UploadProcessor The upload processor instance.
     */
    public static function upload(): UploadProcessor
    {
        return new UploadProcessor();
    }

    /**
     * Poll for file-system changes and invoke callback on each non-empty diff.
     *
     * @param string $path The path to watch.
     * @param callable $onChange Callback invoked when changes detected.
     * @param int $durationSeconds How long to watch in seconds. Defaults to 5.
     * @param int $intervalMilliseconds Polling interval in milliseconds. Defaults to 500.
     * @param bool $recursive Whether to watch subdirectories. Defaults to true.
     * @return array<string, array{mtime: int, size: int}> Final snapshot.
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

    /**
     * Get a file compression handler for this path.
     *
     * @param bool $create If true, create a new ZIP archive if it doesn't exist.
     * @return FileCompression The file compression instance.
     */
    public function compression(bool $create = false): FileCompression
    {
        return new FileCompression($this->path, $create);
    }

    /**
     * Get a directory operations handler for this path.
     *
     * @return DirectoryOperations The directory operations instance.
     */
    public function directory(): DirectoryOperations
    {
        return new DirectoryOperations($this->path);
    }

    /**
     * Check if the file or directory exists.
     *
     * @return bool True if the path exists, false otherwise.
     */
    public function exists(): bool
    {
        return FlysystemHelper::has($this->path);
    }

    /**
     * Get a file operations handler for this path.
     *
     * @return FileOperations The file operations instance.
     */
    public function file(): FileOperations
    {
        return new FileOperations($this->path);
    }

    /**
     * Get metadata for this file or directory.
     *
     * @param bool $humanReadableSize If true, return size in human-readable format.
     * @return array|null The metadata array, or null if the path doesn't exist.
     */
    public function metadata(bool $humanReadableSize = false): ?array
    {
        return MetadataHelper::getAllMetadata($this->path, $humanReadableSize);
    }

    /**
     * Get the MIME type of this file.
     *
     * @return string|null The MIME type, or null if not a file.
     */
    public function mimeType(): ?string
    {
        return MetadataHelper::getMimeType($this->path);
    }

    /**
     * Get the normalized path.
     *
     * @return string The normalized path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get a safe file reader for this path.
     *
     * @param string $mode The file mode to open with. Defaults to 'r'.
     * @param bool $exclusiveLock If true, acquire an exclusive lock.
     * @return SafeFileReader The file reader instance.
     */
    public function reader(string $mode = 'r', bool $exclusiveLock = false): SafeFileReader
    {
        return new SafeFileReader($this->path, $mode, $exclusiveLock);
    }

    /**
     * Get a safe file writer for this path.
     *
     * @param bool $append If true, append to existing file. Defaults to false.
     * @return SafeFileWriter The file writer instance.
     */
    public function writer(bool $append = false): SafeFileWriter
    {
        return new SafeFileWriter($this->path, $append);
    }
}
