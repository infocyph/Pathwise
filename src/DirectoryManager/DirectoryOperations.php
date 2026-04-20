<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\DirectoryManager;

use FilesystemIterator;
use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\DirectoryManager\Concerns\DirectoryOperationsEntryConcern;
use Infocyph\Pathwise\DirectoryManager\Concerns\DirectoryOperationsSyncConcern;
use Infocyph\Pathwise\DirectoryManager\Concerns\DirectoryOperationsZipConcern;
use Infocyph\Pathwise\Exceptions\DirectoryOperationException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Pathwise\Utils\PermissionsHelper;
use InvalidArgumentException;
use League\Flysystem\DirectoryListing;
use League\Flysystem\StorageAttributes;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type StorageEntry array<string, mixed>
 * @phpstan-type DetailedContentItem array{
 *     path: string,
 *     type: string,
 *     size: int,
 *     permissions: string|null,
 *     last_modified: int
 * }
 * @phpstan-type FindCriteria array{
 *     name?: string,
 *     extension?: string,
 *     permissions?: int,
 *     minSize?: int,
 *     maxSize?: int
 * }
 * @phpstan-type SyncReport array{
 *     created: list<string>,
 *     updated: list<string>,
 *     deleted: list<string>,
 *     unchanged: list<string>
 * }
 */
class DirectoryOperations
{
    use DirectoryOperationsEntryConcern;
    use DirectoryOperationsSyncConcern;
    use DirectoryOperationsZipConcern;

    private ExecutionStrategy $executionStrategy = ExecutionStrategy::AUTO;

    /**
     * Constructor to initialize the directory path.
     *
     * @param string $path The path to the directory.
     *
     * @throws InvalidArgumentException If the path is not a valid directory.
     */
    public function __construct(protected string $path)
    {
        $this->path = PathHelper::normalize($path);
    }

    /**
     * Copies the contents of the directory to the specified destination.
     *
     * @param string $destination The path to the destination directory.
     * @return bool True if the copy operation was successful, false otherwise.
     */
    public function copy(string $destination, ?callable $progress = null): bool
    {
        if (!FlysystemHelper::directoryExists($this->path)) {
            throw new DirectoryOperationException("Source directory does not exist: {$this->path}");
        }

        $destination = PathHelper::normalize($destination);
        if (!FlysystemHelper::directoryExists($destination)) {
            FlysystemHelper::createDirectory($destination);
        }

        if ($this->attemptNativeCopy($destination, $progress)) {
            return true;
        }

        $this->emitCopyProgress($progress, 0);

        FlysystemHelper::copyDirectory($this->path, $destination);

        $this->emitCopyProgress($progress, 1);

        return true;
    }

    /**
     * Creates the directory.
     *
     * @param int $permissions The permissions to set for the newly created directory.
     * @param bool $recursive Whether to create the directory recursively.
     * @return bool True if the directory was successfully created, false otherwise.
     */
    public function create(int $permissions = 0755, bool $recursive = true): bool
    {
        if (FlysystemHelper::directoryExists($this->path)) {
            return true;
        }

        if (!$recursive && !$this->parentDirectoryExists($this->path)) {
            return false;
        }

        FlysystemHelper::createDirectory($this->path);
        if ($this->isLocalPath($this->path) && is_dir($this->path)) {
            $this->applyPermissionsSilently($this->path, $permissions);
        }

        return true;
    }

    /**
     * Creates a temporary directory with a unique name in the system's temporary directory.
     *
     * @return string The path to the temporary directory.
     */
    public function createTempDir(): string
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('temp_', true);
        FlysystemHelper::createDirectory($tempDir);

        return $tempDir;
    }

    /**
     * Deletes the directory.
     *
     * @param bool $recursive Whether to delete the contents of the directory first.
     * @return bool True if the directory was successfully deleted, false otherwise.
     */
    public function delete(bool $recursive = false): bool
    {
        if (!FlysystemHelper::directoryExists($this->path)) {
            return true;
        }

        if ($recursive) {
            FlysystemHelper::deleteDirectory($this->path);

            return true;
        }

        if (FlysystemHelper::listContents($this->path, false) !== []) {
            return false;
        }

        FlysystemHelper::deleteDirectory($this->path);

        return true;
    }

    /**
     * Finds files in the current directory based on the given criteria.
     *
     * Criteria that can be specified are:
     * - name: string to search for in the filename
     * - extension: string to match the file extension to
     * - permissions: integer to match the file permissions to
     * - minSize: minimum size of the file
     * - maxSize: maximum size of the file
     *
     * @param FindCriteria $criteria The criteria to match against.
     * @return list<string> A list of file paths that match the criteria.
     */
    public function find(array $criteria = []): array
    {
        $results = [];
        $sourceLocation = $this->storageLocation($this->path);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        foreach ($this->listStorageEntries($this->path, true) as $item) {
            if ($this->entryType($item) !== 'file') {
                continue;
            }

            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $resolvedPath = $this->buildPath($this->path, $relative);
            $size = $this->entrySize($item);

            if (!$this->matchesFindCriteria($criteria, $resolvedPath, $size, $isWindows)) {
                continue;
            }

            $results[] = $resolvedPath;
        }

        return $results;
    }

    /**
     * Flatten the directory structure and return an array of file paths.
     *
     * @param callable|null $filter Optional callback with signature:
     *                              fn(string $path, array $metadata): bool
     * @return list<string> An array of file paths.
     */
    public function flatten(?callable $filter = null): array
    {
        $flattened = [];
        $sourceLocation = $this->storageLocation($this->path);

        foreach ($this->listStorageEntries($this->path, true) as $item) {
            if ($this->entryType($item) !== 'file') {
                continue;
            }

            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $resolvedPath = $this->buildPath($this->path, $relative);
            if (!$this->invokeFilter($filter, $resolvedPath, $item)) {
                continue;
            }

            $flattened[] = $resolvedPath;
        }

        return $flattened;
    }

    /**
     * Get the maximum depth of the directory.
     *
     * @return int The maximum depth of the directory.
     */
    public function getDepth(): int
    {
        $maxDepth = 0;
        $sourceLocation = $this->storageLocation($this->path);

        foreach ($this->listStorageEntries($this->path, true) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $depth = substr_count(trim(str_replace('\\', '/', $relative), '/'), '/');
            $maxDepth = max($maxDepth, $depth);
        }

        return $maxDepth;
    }

    /**
     * Gets an iterator that traverses the local directory tree.
     *
     * @return RecursiveIteratorIterator<RecursiveDirectoryIterator> An iterator that traverses the directory tree.
     */
    public function getIterator(): RecursiveIteratorIterator
    {
        if (!$this->isLocalPath($this->path) || !is_dir($this->path)) {
            throw new DirectoryOperationException("Iterator is only available for local directories: {$this->path}");
        }

        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
        );
    }

    /**
     * Gets the current permissions of the directory.
     *
     * @return int The current permissions of the directory.
     */
    public function getPermissions(): int
    {
        if (!$this->isLocalPath($this->path) || !file_exists($this->path)) {
            throw new DirectoryOperationException("Unable to retrieve permissions for non-local directory: {$this->path}");
        }

        $permissions = fileperms($this->path);
        if ($permissions === false) {
            throw new DirectoryOperationException("Unable to retrieve permissions for directory: {$this->path}");
        }

        return $permissions & 0777;
    }

    /**
     * Returns an array of items in the directory.
     *
     * @param bool $detailed Whether to return detailed information about each item.
     * @param callable|null $filter Optional callback with signature:
     *                              fn(string $path, array $metadata): bool
     * @return array<int, string|DetailedContentItem> An array of items in the directory.
     */
    public function listContents(bool $detailed = false, ?callable $filter = null): array
    {
        $contents = [];
        $sourceLocation = $this->storageLocation($this->path);

        foreach ($this->listStorageEntries($this->path, true) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $resolvedPath = $this->buildPath($this->path, $relative);
            if (!$this->invokeFilter($filter, $resolvedPath, $item)) {
                continue;
            }

            if (!$detailed) {
                $contents[] = $resolvedPath;

                continue;
            }

            $contents[] = $this->buildDetailedContentItem($resolvedPath, $item);
        }

        return $contents;
    }

    /**
     * List directory contents as a DirectoryListing object.
     *
     * @param bool $deep Whether to list contents recursively. Defaults to true.
     * @return DirectoryListing<StorageAttributes> The directory listing.
     */
    public function listContentsListing(bool $deep = true): DirectoryListing
    {
        return FlysystemHelper::listContentsListing($this->path, $deep);
    }

    /**
     * Lists the current permissions of the directory in a string like 'rwxr-x--'.
     *
     * @return string The current permissions of the directory.
     */
    public function listPermissions(): string
    {
        return PermissionsHelper::formatPermissions($this->getPermissions());
    }

    /**
     * Returns a sorted array of the directory's first-level contents.
     *
     * @param string $sortOrder The sort order of the contents. Defaults to 'asc'.
     * @return list<string> An array of the directory's contents, sorted by the given order.
     */
    public function listSortedContents(string $sortOrder = 'asc'): array
    {
        $sourceLocation = $this->storageLocation($this->path);
        $contents = [];

        foreach ($this->listStorageEntries($this->path, false) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $contents[] = basename(str_replace('\\', '/', $relative));
        }

        sort($contents, SORT_STRING);
        if ($sortOrder !== 'asc') {
            $contents = array_reverse($contents);
        }

        return $contents;
    }

    /**
     * Moves the directory to the given destination.
     *
     * @param string $destination The path to move the directory to.
     * @return bool True if the directory was successfully moved, false otherwise.
     */
    public function move(string $destination): bool
    {
        if (!FlysystemHelper::directoryExists($this->path)) {
            return false;
        }

        FlysystemHelper::moveDirectory($this->path, PathHelper::normalize($destination));

        return true;
    }

    /**
     * Set the execution strategy for directory operations.
     *
     * @param ExecutionStrategy $executionStrategy The execution strategy to use.
     * @return self This instance for method chaining.
     */
    public function setExecutionStrategy(ExecutionStrategy $executionStrategy): self
    {
        $this->executionStrategy = $executionStrategy;

        return $this;
    }

    /**
     * Set the permissions of the directory to the given value.
     *
     * @param int $permissions The new permissions for the directory.
     * @return bool True if the permissions were successfully set, false otherwise.
     */
    public function setPermissions(int $permissions): bool
    {
        if (!$this->isLocalPath($this->path) || !file_exists($this->path)) {
            throw new DirectoryOperationException("Unable to set permissions for non-local directory: {$this->path}");
        }

        return chmod($this->path, $permissions);
    }

    /**
     * Set the visibility of the directory.
     *
     * @param string $visibility The visibility to set (e.g., 'public' or 'private').
     * @return self This instance for method chaining.
     */
    public function setVisibility(string $visibility): self
    {
        FlysystemHelper::setVisibility($this->path, $visibility);

        return $this;
    }

    /**
     * Calculates the total size of all files in the directory.
     *
     * @param callable|null $filter Optional callback with signature:
     *                              fn(string $path, array $metadata): bool
     * @return int The total size of all files that pass the filter in bytes.
     */
    public function size(?callable $filter = null): int
    {
        $size = 0;
        $sourceLocation = $this->storageLocation($this->path);

        foreach ($this->listStorageEntries($this->path, true) as $item) {
            if ($this->entryType($item) !== 'file') {
                continue;
            }

            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $resolvedPath = $this->buildPath($this->path, $relative);
            if (!$this->invokeFilter($filter, $resolvedPath, $item)) {
                continue;
            }

            $size += $this->entrySize($item);
        }

        return $size;
    }

    /**
     * Mirror the source directory to destination and return a diff report.
     *
     * @return SyncReport
     */
    public function syncTo(string $destination, bool $deleteOrphans = true, ?callable $progress = null): array
    {
        $this->assertSourceDirectoryExists();
        $destination = $this->ensureDirectoryExists($destination);
        $report = $this->newSyncReport();
        $sourceEntries = [];

        $sourceLocation = $this->storageLocation($this->path);
        $sourceItems = $this->listStorageEntries($this->path, true);
        $total = count($sourceItems);
        $current = 0;

        foreach ($sourceItems as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $current++;
            $this->syncOneItem($destination, $relative, $item, $sourceEntries, $report);
            $this->emitSyncProgress($progress, $relative, $current, $total);
        }

        if ($deleteOrphans) {
            $this->deleteSyncOrphans($destination, $sourceEntries, $report);
        }

        return $this->finalizeSyncReport($report);
    }

    /**
     * Extracts the contents of a zip file to the directory represented by this object.
     *
     * @param string $source The path to the zip file.
     * @return bool True if the extraction was successful, false otherwise.
     */
    public function unzip(string $source): bool
    {
        $source = PathHelper::normalize($source);
        $this->assertZipSourceExists($source);
        $this->ensureDirectoryExists($this->path);
        [$localSource, $cleanupSource] = $this->prepareLocalZipSource($source);

        try {
            if ($this->tryNativeUnzip($localSource, $source)) {
                return true;
            }

            $this->extractZipContents($localSource, $source);

            return true;
        } finally {
            $this->cleanupTemporaryFile($cleanupSource, $localSource);
        }
    }

    /**
     * Get the visibility of the directory.
     *
     * @return string|null The visibility, or null if not available.
     * @throws DirectoryOperationException If the directory does not exist.
     */
    public function visibility(): ?string
    {
        if (!FlysystemHelper::directoryExists($this->path)) {
            throw new DirectoryOperationException("Directory does not exist: {$this->path}");
        }

        return FlysystemHelper::visibility($this->path);
    }

    /**
     * Zip the contents of the directory to a file.
     *
     * @param string $destination The path to the zip file.
     * @return bool True if the zip was created successfully, false otherwise.
     */
    public function zip(string $destination): bool
    {
        $this->assertSourceDirectoryExists();
        $destination = PathHelper::normalize($destination);
        $useLocalDestination = $this->isLocalPath($destination);

        if ($this->tryNativeZip($destination, $useLocalDestination)) {
            return true;
        }

        $zipPath = $this->prepareZipPath($destination, $useLocalDestination);
        $zip = $this->openZipArchive($zipPath, $destination, $useLocalDestination);

        try {
            $this->addContentsToZip($zip, $zipPath);
        } finally {
            $zip->close();
        }

        if (!$useLocalDestination) {
            $this->persistZipToDestination($zipPath, $destination);
        }

        return true;
    }
}
