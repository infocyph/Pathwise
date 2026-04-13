<?php

namespace Infocyph\Pathwise\DirectoryManager;

use FilesystemIterator;
use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\DirectoryOperationException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Pathwise\Utils\PermissionsHelper;
use InvalidArgumentException;
use League\Flysystem\DirectoryListing;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class DirectoryOperations
{
    private ExecutionStrategy $executionStrategy = ExecutionStrategy::AUTO;

    /**
     * Constructor to initialize the directory path.
     *
     * @param  string  $path  The path to the directory.
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
     * @param  string  $destination  The path to the destination directory.
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
     * @param  int  $permissions  The permissions to set for the newly created directory.
     * @param  bool  $recursive  Whether to create the directory recursively.
     * @return bool True if the directory was successfully created, false otherwise.
     */
    public function create(int $permissions = 0755, bool $recursive = true): bool
    {
        if (FlysystemHelper::directoryExists($this->path)) {
            return true;
        }

        FlysystemHelper::createDirectory($this->path);
        if ($this->isLocalPath($this->path) && is_dir($this->path)) {
            @chmod($this->path, $permissions);
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
     * @param  bool  $recursive  Whether to delete the contents of the directory first.
     * @return bool True if the directory was successfully deleted, false otherwise.
     */
    public function delete(bool $recursive = false): bool
    {
        if (!FlysystemHelper::directoryExists($this->path)) {
            return true;
        }

        if ($recursive) {
            FlysystemHelper::deleteDirectory($this->path);

            return !FlysystemHelper::directoryExists($this->path);
        }

        if (FlysystemHelper::listContents($this->path, false) !== []) {
            return false;
        }

        FlysystemHelper::deleteDirectory($this->path);

        return !FlysystemHelper::directoryExists($this->path);
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
     * @param  array  $criteria  The criteria to match against
     * @return array A list of file paths that match the criteria
     */
    public function find(array $criteria = []): array
    {
        $results = [];
        $sourceLocation = $this->storageLocation($this->path);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        foreach (FlysystemHelper::listContents($this->path, true) as $item) {
            if (($item['type'] ?? null) !== 'file') {
                continue;
            }

            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
            if ($relative === '') {
                continue;
            }

            $resolvedPath = $this->buildPath($this->path, $relative);
            $size = (int) ($item['file_size'] ?? 0);

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
     * @param  callable|null  $filter  Optional callback with signature:
     *                                  fn(string $path, array $metadata): bool
     * @return array An array of file paths.
     */
    public function flatten(?callable $filter = null): array
    {
        $flattened = [];
        $sourceLocation = $this->storageLocation($this->path);

        foreach (FlysystemHelper::listContents($this->path, true) as $item) {
            if (($item['type'] ?? null) !== 'file') {
                continue;
            }

            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
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

        foreach (FlysystemHelper::listContents($this->path, true) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
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
     * @return RecursiveIteratorIterator An iterator that traverses the directory tree.
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
     * @param  bool  $detailed  Whether to return detailed information about each item.
     * @param  callable|null  $filter  Optional callback with signature:
     *                                 fn(string $path, array $metadata): bool
     * @return array An array of items in the directory.
     */
    public function listContents(bool $detailed = false, ?callable $filter = null): array
    {
        $contents = [];
        $sourceLocation = $this->storageLocation($this->path);

        foreach (FlysystemHelper::listContents($this->path, true) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
            if ($relative === '') {
                continue;
            }

            $resolvedPath = $this->buildPath($this->path, $relative);
            if (!$this->invokeFilter($filter, $resolvedPath, $item)) {
                continue;
            }

            if ($detailed) {
                $permissions = null;
                if ($this->isLocalPath($resolvedPath) && file_exists($resolvedPath)) {
                    $permissionBits = fileperms($resolvedPath);
                    if (is_int($permissionBits)) {
                        $permissions = PermissionsHelper::formatPermissions($permissionBits);
                    }
                }

                $contents[] = [
                    'path' => $resolvedPath,
                    'type' => (string) ($item['type'] ?? 'file'),
                    'size' => (int) ($item['file_size'] ?? 0),
                    'permissions' => $permissions ?? (string) ($item['visibility'] ?? ''),
                    'last_modified' => (int) ($item['last_modified'] ?? 0),
                ];
            } else {
                $contents[] = $resolvedPath;
            }
        }

        return $contents;
    }

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
     * @param  string  $sortOrder  The sort order of the contents. Defaults to 'asc'.
     * @return array An array of the directory's contents, sorted by the given order.
     */
    public function listSortedContents(string $sortOrder = 'asc'): array
    {
        $sourceLocation = $this->storageLocation($this->path);
        $contents = [];

        foreach (FlysystemHelper::listContents($this->path, false) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
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
     * @param  string  $destination  The path to move the directory to.
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

    public function setExecutionStrategy(ExecutionStrategy $executionStrategy): self
    {
        $this->executionStrategy = $executionStrategy;

        return $this;
    }

    /**
     * Set the permissions of the directory to the given value.
     *
     * @param  int  $permissions  The new permissions for the directory.
     * @return bool True if the permissions were successfully set, false otherwise.
     */
    public function setPermissions(int $permissions): bool
    {
        if (!$this->isLocalPath($this->path) || !file_exists($this->path)) {
            throw new DirectoryOperationException("Unable to set permissions for non-local directory: {$this->path}");
        }

        return chmod($this->path, $permissions);
    }

    public function setVisibility(string $visibility): self
    {
        FlysystemHelper::setVisibility($this->path, $visibility);

        return $this;
    }

    /**
     * Calculates the total size of all files in the directory.
     *
     * @param  callable|null  $filter  Optional callback with signature:
     *                                 fn(string $path, array $metadata): bool
     * @return int The total size of all files that pass the filter in bytes.
     */
    public function size(?callable $filter = null): int
    {
        $size = 0;
        $sourceLocation = $this->storageLocation($this->path);

        foreach (FlysystemHelper::listContents($this->path, true) as $item) {
            if (($item['type'] ?? null) !== 'file') {
                continue;
            }

            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
            if ($relative === '') {
                continue;
            }

            $resolvedPath = $this->buildPath($this->path, $relative);
            if (!$this->invokeFilter($filter, $resolvedPath, $item)) {
                continue;
            }

            $size += (int) ($item['file_size'] ?? 0);
        }

        return $size;
    }

    /**
     * Mirror the source directory to destination and return a diff report.
     *
     * @return array{created: array, updated: array, deleted: array, unchanged: array}
     */
    public function syncTo(string $destination, bool $deleteOrphans = true, ?callable $progress = null): array
    {
        $this->assertSourceDirectoryExists();
        $destination = $this->ensureDirectoryExists($destination);
        $report = $this->newSyncReport();
        $sourceEntries = [];

        $sourceLocation = $this->storageLocation($this->path);
        $sourceItems = FlysystemHelper::listContents($this->path, true);
        $total = count($sourceItems);
        $current = 0;

        foreach ($sourceItems as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
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

        return $report;
    }

    /**
     * Extracts the contents of a zip file to the directory represented by this object.
     *
     * @param  string  $source  The path to the zip file.
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
     * @param  string  $destination  The path to the zip file.
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

    /**
     * Deletes all files and directories in the given local directory.
     *
     * @param  string  $directory  The directory to delete contents of.
     * @return bool True if the directory contents were successfully deleted, false otherwise.
     */
    protected function deleteDirectoryContents(string $directory): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());

                continue;
            }

            unlink($file->getRealPath());
        }

        return true;
    }

    private function addContentsToZip(ZipArchive $zip, string $zipPath): void
    {
        if ($this->isLocalPath($this->path) && is_dir($this->path)) {
            $this->addLocalContentsToZip($zip, $zipPath);

            return;
        }

        $this->addFlysystemContentsToZip($zip);
    }

    private function addFlysystemContentsToZip(ZipArchive $zip): void
    {
        $sourceLocation = $this->storageLocation($this->path);
        foreach (FlysystemHelper::listContents($this->path, true) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, (string) ($item['path'] ?? ''));
            if ($relative === '') {
                continue;
            }

            $zipPathName = str_replace('\\', '/', $relative);
            if (($item['type'] ?? null) === 'dir') {
                $zip->addEmptyDir(rtrim($zipPathName, '/'));
                continue;
            }

            $zip->addFromString($zipPathName, FlysystemHelper::read($this->buildPath($this->path, $relative)));
        }
    }

    private function addLocalContentsToZip(ZipArchive $zip, string $zipPath): void
    {
        $normalizedZipPath = PathHelper::normalize($zipPath);
        $directoryIterator = new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            $currentPath = PathHelper::normalize($file->getPathname());
            if ($currentPath === $normalizedZipPath) {
                continue;
            }

            $subPathName = str_replace('\\', '/', $iterator->getInnerIterator()->getSubPathName());
            if ($file->isDir()) {
                $zip->addEmptyDir($subPathName);
                continue;
            }

            $zip->addFile($file->getPathname(), $subPathName);
        }
    }

    private function assertSourceDirectoryExists(): void
    {
        if (!FlysystemHelper::directoryExists($this->path)) {
            throw new DirectoryOperationException("Source directory does not exist: {$this->path}");
        }
    }

    private function assertZipSourceExists(string $source): void
    {
        if (!FlysystemHelper::fileExists($source)) {
            throw new DirectoryOperationException("ZIP source does not exist: {$source}");
        }
    }

    private function attemptNativeCopy(string $destination, ?callable $progress): bool
    {
        if (!$this->canAttemptNativeCopy($destination)) {
            return false;
        }

        $this->emitCopyProgress($progress, 0);
        $native = NativeOperationsAdapter::copyDirectory($this->path, $destination, false);
        if ($native['success']) {
            $this->emitCopyProgress($progress, 1);

            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new DirectoryOperationException("Native directory copy failed for '{$this->path}' to '{$destination}'.");
        }

        return false;
    }

    private function buildPath(string $basePath, string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return PathHelper::normalize($basePath);
        }

        if (PathHelper::hasScheme($basePath)) {
            return rtrim(str_replace('\\', '/', $basePath), '/') . '/' . $relativePath;
        }

        return PathHelper::join($basePath, $relativePath);
    }

    private function canAttemptNativeCopy(string $destination): bool
    {
        return $this->executionStrategy !== ExecutionStrategy::PHP
            && NativeOperationsAdapter::canUseNativeDirectoryCopy()
            && $this->isLocalPath($this->path)
            && $this->isLocalPath($destination);
    }

    private function cleanupTemporaryFile(bool $shouldCleanup, string $path): void
    {
        if ($shouldCleanup && is_file($path)) {
            @unlink($path);
        }
    }

    private function copyIfSyncRequired(string $sourcePath, string $targetPath): string
    {
        if (!FlysystemHelper::fileExists($targetPath)) {
            FlysystemHelper::copy($sourcePath, $targetPath);

            return 'created';
        }

        $sourceHash = FlysystemHelper::checksum($sourcePath, 'sha256');
        $targetHash = FlysystemHelper::checksum($targetPath, 'sha256');
        if (!is_string($sourceHash) || !is_string($targetHash) || !hash_equals($sourceHash, $targetHash)) {
            FlysystemHelper::copy($sourcePath, $targetPath);

            return 'updated';
        }

        return 'unchanged';
    }

    private function deleteSyncOrphans(string $destination, array $sourceEntries, array &$report): void
    {
        $destinationLocation = $this->storageLocation($destination);
        $destinationItems = FlysystemHelper::listContents($destination, true);

        usort(
            $destinationItems,
            static fn(array $a, array $b): int => strlen((string) ($b['path'] ?? '')) <=> strlen((string) ($a['path'] ?? '')),
        );

        foreach ($destinationItems as $item) {
            $relative = $this->relativeStoragePath($destinationLocation, (string) ($item['path'] ?? ''));
            if ($relative === '' || isset($sourceEntries[$relative])) {
                continue;
            }

            $targetPath = $this->buildPath($destination, $relative);
            if (($item['type'] ?? null) === 'dir') {
                FlysystemHelper::deleteDirectory($targetPath);
                $report['deleted'][] = $relative . '/';
                continue;
            }

            FlysystemHelper::delete($targetPath);
            $report['deleted'][] = $relative;
        }
    }

    private function emitCopyProgress(?callable $progress, int $current): void
    {
        if (!is_callable($progress)) {
            return;
        }

        $progress([
            'operation' => 'copy',
            'path' => $this->path,
            'current' => $current,
            'total' => 1,
        ]);
    }

    private function emitSyncProgress(?callable $progress, string $relative, int $current, int $total): void
    {
        if (!is_callable($progress)) {
            return;
        }

        $progress([
            'operation' => 'sync',
            'path' => $relative,
            'current' => $current,
            'total' => max(1, $total),
        ]);
    }

    private function ensureDirectoryExists(string $path): string
    {
        $path = PathHelper::normalize($path);
        if (!FlysystemHelper::directoryExists($path)) {
            FlysystemHelper::createDirectory($path);
        }

        return $path;
    }

    private function ensureZipEntryDirectory(string $entry): void
    {
        $relativeDir = pathinfo($entry, PATHINFO_DIRNAME);
        if ($relativeDir === '' || $relativeDir === '.') {
            return;
        }

        $targetDir = $this->buildPath($this->path, str_replace('\\', '/', $relativeDir));
        if (!FlysystemHelper::directoryExists($targetDir)) {
            FlysystemHelper::createDirectory($targetDir);
        }
    }

    private function extractSingleZipEntry(ZipArchive $zip, int $index): void
    {
        $entry = $this->sanitizeZipEntryPath((string) $zip->getNameIndex($index));
        if ($entry === '') {
            return;
        }

        if (str_ends_with($entry, '/')) {
            FlysystemHelper::createDirectory($this->buildPath($this->path, rtrim($entry, '/')));

            return;
        }

        $this->ensureZipEntryDirectory($entry);
        $contents = $zip->getFromIndex($index);
        if (!is_string($contents)) {
            throw new DirectoryOperationException("Unable to extract ZIP entry: {$entry}");
        }

        FlysystemHelper::write($this->buildPath($this->path, $entry), $contents);
    }

    private function extractZipContents(string $localSource, string $source): void
    {
        $zip = new ZipArchive();
        if ($zip->open($localSource) !== true) {
            throw new DirectoryOperationException("Unable to open ZIP source: {$source}");
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $this->extractSingleZipEntry($zip, $i);
            }
        } finally {
            $zip->close();
        }
    }

    private function invokeFilter(?callable $filter, string $path, array $metadata): bool
    {
        if ($filter === null) {
            return true;
        }

        try {
            return (bool) $filter($path, $metadata);
        } catch (\ArgumentCountError) {
            return (bool) $filter($path);
        }
    }

    private function isLocalPath(string $path): bool
    {
        return !PathHelper::hasScheme($path) && PathHelper::isAbsolute($path);
    }

    private function matchesFindCriteria(array $criteria, string $resolvedPath, int $size, bool $isWindows): bool
    {
        return (empty($criteria['name']) || str_contains(basename($resolvedPath), (string) $criteria['name']))
            && (empty($criteria['extension']) || pathinfo($resolvedPath, PATHINFO_EXTENSION) === (string) $criteria['extension'])
            && $this->matchesPermissionsCriteria($criteria, $resolvedPath, $isWindows)
            && (empty($criteria['minSize']) || $size >= (int) $criteria['minSize'])
            && (empty($criteria['maxSize']) || $size <= (int) $criteria['maxSize']);
    }

    private function matchesPermissionsCriteria(array $criteria, string $resolvedPath, bool $isWindows): bool
    {
        if (empty($criteria['permissions']) || $isWindows) {
            return true;
        }

        if (!$this->isLocalPath($resolvedPath) || !file_exists($resolvedPath)) {
            return false;
        }

        $permissions = fileperms($resolvedPath);

        return is_int($permissions) && ($permissions & 0777) === (int) $criteria['permissions'];
    }

    /**
     * @return array{created: array, updated: array, deleted: array, unchanged: array}
     */
    private function newSyncReport(): array
    {
        return [
            'created' => [],
            'updated' => [],
            'deleted' => [],
            'unchanged' => [],
        ];
    }

    private function openZipArchive(string $zipPath, string $destination, bool $useLocalDestination): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
            return $zip;
        }

        if (!$useLocalDestination && is_file($zipPath)) {
            @unlink($zipPath);
        }

        throw new DirectoryOperationException("Unable to create ZIP archive at '{$destination}'.");
    }

    private function persistZipToDestination(string $zipPath, string $destination): void
    {
        $stream = fopen($zipPath, 'rb');
        if (!is_resource($stream)) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            throw new DirectoryOperationException("Unable to stream ZIP archive at '{$zipPath}'.");
        }

        try {
            FlysystemHelper::writeStream($destination, $stream);
        } finally {
            fclose($stream);
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * @return array{string, bool}
     */
    private function prepareLocalZipSource(string $source): array
    {
        if ($this->isLocalPath($source) && is_file($source)) {
            return [$source, false];
        }

        $tempSource = tempnam(sys_get_temp_dir(), 'pathwise_unzip_');
        if ($tempSource === false) {
            throw new DirectoryOperationException('Unable to create temporary ZIP source.');
        }

        $sourceStream = FlysystemHelper::readStream($source);
        $targetStream = fopen($tempSource, 'wb');
        if (!is_resource($sourceStream) || !is_resource($targetStream)) {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
            if (is_resource($targetStream)) {
                fclose($targetStream);
            }
            @unlink($tempSource);
            throw new DirectoryOperationException("Unable to read ZIP source: {$source}");
        }

        stream_copy_to_stream($sourceStream, $targetStream);
        fclose($sourceStream);
        fclose($targetStream);

        return [$tempSource, true];
    }

    private function prepareZipPath(string $destination, bool $useLocalDestination): string
    {
        if (!$useLocalDestination) {
            $tempZip = tempnam(sys_get_temp_dir(), 'pathwise_zip_');
            if ($tempZip === false) {
                throw new DirectoryOperationException('Unable to allocate temporary ZIP path.');
            }

            @unlink($tempZip);

            return $tempZip;
        }

        $parent = dirname($destination);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        return $destination;
    }

    private function relativeStoragePath(string $baseLocation, string $itemPath): string
    {
        $normalizedBase = trim(str_replace('\\', '/', $baseLocation), '/');
        $normalizedPath = trim(str_replace('\\', '/', $itemPath), '/');

        if ($normalizedBase === '') {
            return $normalizedPath;
        }

        if ($normalizedPath === $normalizedBase) {
            return '';
        }

        if (str_starts_with($normalizedPath, $normalizedBase . '/')) {
            return substr($normalizedPath, strlen($normalizedBase) + 1);
        }

        return $normalizedPath;
    }

    private function sanitizeZipEntryPath(string $entry): string
    {
        $normalized = str_replace('\\', '/', $entry);
        $trimmed = ltrim($normalized, '/');
        if ($trimmed === '') {
            return '';
        }

        $safePath = preg_replace('#/+#', '/', $trimmed) ?? '';
        $safePath = preg_replace('#(^|/)\./#', '$1', $safePath) ?? $safePath;
        $trimmedSafePath = rtrim($safePath, '/');

        if (
            str_contains($trimmedSafePath, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $trimmedSafePath) === 1
            || preg_match('/^[A-Za-z]:($|\/)/', $trimmedSafePath) === 1
        ) {
            throw new DirectoryOperationException("Unsafe ZIP entry path detected: {$entry}");
        }

        if ($trimmedSafePath === '') {
            return '';
        }

        return str_ends_with($normalized, '/') ? $trimmedSafePath . '/' : $trimmedSafePath;
    }

    private function storageLocation(string $directoryPath): string
    {
        [, $location] = FlysystemHelper::resolveDirectory($directoryPath);

        return trim(str_replace('\\', '/', $location), '/');
    }

    private function syncOneItem(string $destination, string $relative, array $item, array &$sourceEntries, array &$report): void
    {
        $type = (string) ($item['type'] ?? 'file');
        $sourceEntries[$relative] = $type;

        if ($type === 'dir') {
            $targetPath = $this->buildPath($destination, $relative);
            if (!FlysystemHelper::directoryExists($targetPath)) {
                FlysystemHelper::createDirectory($targetPath);
                $report['created'][] = $relative . '/';
            }

            return;
        }

        $sourcePath = $this->buildPath($this->path, $relative);
        $targetPath = $this->buildPath($destination, $relative);
        $result = $this->copyIfSyncRequired($sourcePath, $targetPath);
        $report[$result][] = $relative;
    }

    private function tryNativeUnzip(string $localSource, string $source): bool
    {
        if (
            $this->executionStrategy === ExecutionStrategy::PHP
            || !NativeOperationsAdapter::canUseNativeCompression()
            || !$this->isLocalPath($this->path)
        ) {
            return false;
        }

        $native = NativeOperationsAdapter::decompressZip($localSource, $this->path);
        if ($native['success']) {
            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new DirectoryOperationException("Native unzip failed for '{$source}' to '{$this->path}'.");
        }

        return false;
    }

    private function tryNativeZip(string $destination, bool $useLocalDestination): bool
    {
        if (
            $this->executionStrategy === ExecutionStrategy::PHP
            || !NativeOperationsAdapter::canUseNativeCompression()
            || !$this->isLocalPath($this->path)
            || !$useLocalDestination
        ) {
            return false;
        }

        $native = NativeOperationsAdapter::compressToZip($this->path, $destination);
        if ($native['success']) {
            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new DirectoryOperationException("Native zip failed for '{$this->path}' to '{$destination}'.");
        }

        return false;
    }
}
