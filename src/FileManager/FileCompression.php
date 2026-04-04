<?php

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use ZipArchive;

class FileCompression
{
    private readonly ZipArchive $zip;
    private bool $cleanupWorkingZipPath = false;
    private ?string $defaultDecompressionPath = null;
    private int $encryptionAlgorithm;
    private array $excludePatterns = [];
    private ExecutionStrategy $executionStrategy = ExecutionStrategy::PHP;
    private array $hooks = [];
    private array $ignoreFileNames = ['.pathwiseignore', '.gitignore'];
    private array $ignorePatterns = [];
    private array $includePatterns = [];
    private bool $isOpen = false;
    /** @var array<string, true> */
    private array $localizedCleanupPaths = [];
    private mixed $logger = null;
    private ?string $password = null;
    private mixed $progressCallback = null;
    private int $progressCurrent = 0;
    private int $progressTotal = 0;
    private bool $syncWorkingZipOnClose = false;
    private string $workingZipPath;

    /**
     * Constructor to set the ZIP file path.
     *
     * @param string $zipFilePath Path to the ZIP file.
     * @param bool $create If true, create a new ZIP file if it doesn't exist.
     * @throws CompressionException
     */
    public function __construct(private readonly string $zipFilePath, bool $create = false)
    {
        $this->zip = new ZipArchive();
        $this->workingZipPath = $this->resolveWorkingZipPath($create);

        // Set encryption algorithm if supported
        $this->encryptionAlgorithm = defined('ZipArchive::EM_AES_256') ? ZipArchive::EM_AES_256 : 0;

        // Open the archive with CREATE flag if specified
        $flags = $create ? ZipArchive::CREATE : 0;
        $this->openZip($flags);
    }

    /**
     * Automatically closes the ZIP archive when the object is destroyed.
     */
    public function __destruct()
    {
        try {
            $this->closeZip();
        } catch (\Throwable) {
            // Never throw from destructors.
        } finally {
            $this->cleanupDeferredLocalizedPaths();
            if ($this->cleanupWorkingZipPath && is_file($this->workingZipPath)) {
                @unlink($this->workingZipPath);
            }
        }
    }


    /**
     * Adds a single file to the current ZIP archive.
     *
     * @param string $filePath The path to the file to be added.
     * @param string|null $zipPath The path in the ZIP archive where the file should be stored.
     *     If not provided, the file will be stored in the root directory of the ZIP file,
     *     with its original name.
     *
     * @return $this
     */
    public function addFile(string $filePath, ?string $zipPath = null): self
    {
        $this->reopenIfNeeded();
        if (!FlysystemHelper::fileExists($filePath)) {
            throw new CompressionException("File does not exist: $filePath");
        }
        $this->triggerHook('beforeAdd', $filePath);
        $this->log("Adding file: $filePath");
        $zipPath ??= basename($filePath);
        $zipPath = $this->normalizeZipPath($zipPath);

        $isLocalFile = !PathHelper::hasScheme($filePath) && is_file($filePath);

        if ($this->password) {
            $this->zip->setPassword($this->password);
            if ($isLocalFile) {
                if (!$this->zip->addFile($filePath, $zipPath)) {
                    throw new CompressionException("Failed to add file to ZIP: $filePath");
                }
            } else {
                if (!$this->zip->addFromString($zipPath, FlysystemHelper::read($filePath))) {
                    throw new CompressionException("Failed to add file to ZIP: $filePath");
                }
            }
            $this->zip->setEncryptionName($zipPath, $this->encryptionAlgorithm);
        } else {
            if ($isLocalFile) {
                if (!$this->zip->addFile($filePath, $zipPath)) {
                    throw new CompressionException("Failed to add file to ZIP: $filePath");
                }
            } else {
                if (!$this->zip->addFromString($zipPath, FlysystemHelper::read($filePath))) {
                    throw new CompressionException("Failed to add file to ZIP: $filePath");
                }
            }
        }

        $this->progressTotal = max(1, $this->progressTotal);
        $this->advanceProgress('compress', $zipPath);

        $this->triggerHook('afterAdd', $filePath);
        return $this;
    }


    /**
     * Batch add multiple files to the current ZIP archive.
     *
     * @param array $files An associative array of file paths mapped to their
     *     desired paths inside the ZIP archive. If a value is not provided for
     *     a key, the basename of the file will be used as the path in the ZIP
     *     archive.
     * @return $this
     */
    public function batchAddFiles(array $files): self
    {
        $this->reopenIfNeeded();
        $this->log("Batch adding files.");
        foreach ($files as $filePath => $zipPath) {
            $this->addFile($filePath, $zipPath);
        }
        return $this;
    }


    /**
     * Batch extract multiple files from the current ZIP archive.
     *
     * @param array $files An associative array mapping ZIP paths to local paths.
     * @param string $destination The destination directory to extract to.
     *
     * @return self
     *
     * @throws Exception If any of the files fail to extract.
     */
    public function batchExtractFiles(array $files, string $destination): self
    {
        $this->reopenIfNeeded();
        $destination = PathHelper::normalize($destination);
        if (!FlysystemHelper::directoryExists($destination)) {
            FlysystemHelper::createDirectory($destination);
        }
        $this->log("Batch extracting files.");
        $this->progressCurrent = 0;
        $this->progressTotal = count($files);
        foreach ($files as $zipPath => $localPath) {
            $zipPath = $this->normalizeZipPath((string) $zipPath);
            $localPath = ltrim(PathHelper::normalize((string) $localPath), DIRECTORY_SEPARATOR);
            $targetPath = PathHelper::join($destination, $localPath);

            if (str_ends_with($zipPath, '/')) {
                if (!FlysystemHelper::directoryExists($targetPath)) {
                    FlysystemHelper::createDirectory($targetPath);
                }
                continue;
            }

            $content = $this->zip->getFromName($zipPath);
            if ($content === false) {
                throw new CompressionException("File not found in ZIP archive: $zipPath.");
            }

            $targetDir = dirname($targetPath);
            if (!FlysystemHelper::directoryExists($targetDir)) {
                FlysystemHelper::createDirectory($targetDir);
            }

            FlysystemHelper::write($targetPath, $content);

            $this->advanceProgress('decompress', $zipPath);
        }
        return $this;
    }


    /**
     * Check the integrity of the current ZIP archive.
     *
     * This function checks the status of the current ZIP archive and returns
     * true if the archive is valid and false otherwise.
     *
     * @return bool True if the archive is valid, false otherwise.
     */
    public function checkIntegrity(): bool
    {
        $this->reopenIfNeeded();
        return $this->zip->status === ZipArchive::ER_OK;
    }


    /**
     * Compress a file or directory into the ZIP archive.
     *
     * @param string $source The path to the file or directory to compress.
     * @return static
     */
    public function compress(string $source): self
    {
        $this->reopenIfNeeded();
        $cleanupPath = null;
        $resolvedSource = $this->localizeCompressionSource($source, $cleanupPath);
        $this->deferLocalizedCleanupPath($cleanupPath);

        if ($this->shouldAttemptNativeCompression() && NativeOperationsAdapter::canUseNativeCompression()) {
            $this->closeZip();
            $native = NativeOperationsAdapter::compressToZip($resolvedSource, $this->workingZipPath);
            if ($native['success']) {
                if (is_callable($this->progressCallback)) {
                    ($this->progressCallback)([
                        'operation' => 'compress',
                        'path' => $resolvedSource,
                        'current' => 1,
                        'total' => 1,
                    ]);
                }
                $this->openZip();

                return $this;
            }

            if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
                throw new CompressionException("Native compression failed for source: {$resolvedSource}");
            }

            $this->openZip();
        }

        $this->loadIgnorePatterns($resolvedSource);
        $this->initializeProgress($resolvedSource);
        $this->log("Compressing source: $resolvedSource");
        $this->addFilesToZip($resolvedSource, $this->zip);

        return $this;
    }


    /**
     * Compress a file or directory, but only include files with the specified
     * extensions in the ZIP archive.
     *
     * @param string $source The path to the file or directory to compress.
     * @param array $extensions An array of file extensions to include.
     * @return static
     */
    public function compressWithFilter(string $source, array $extensions = []): self
    {
        $this->reopenIfNeeded();
        $cleanupPath = null;
        $resolvedSource = $this->localizeCompressionSource($source, $cleanupPath);
        $this->deferLocalizedCleanupPath($cleanupPath);

        $this->loadIgnorePatterns($resolvedSource);
        $this->initializeProgress($resolvedSource, $extensions);
        $this->log("Compressing source with filter: $resolvedSource");
        $this->addFilesToZipWithFilter($resolvedSource, $this->zip, null, $extensions);

        return $this;
    }


    /**
     * Decompress the current ZIP archive to a directory.
     *
     * If no destination path is provided, the default path set with
     * `setDefaultDecompressionPath` is used. If no default path has been set,
     * an exception is thrown.
     *
     * If a password has been set with `setPassword`, the ZIP archive is
     * decrypted with this password.
     *
     * @param string|null $destination The path to decompress the ZIP archive to.
     * @return static
     * @throws CompressionException
     */
    public function decompress(?string $destination = null): self
    {
        $this->reopenIfNeeded();
        $destination ??= $this->defaultDecompressionPath;
        if (!$destination) {
            throw new CompressionException("No destination path provided for decompression.");
        }
        $destination = PathHelper::normalize($destination);
        $isRemoteDestination = $this->isRemotePath($destination);
        $extractDestination = $destination;
        $extractTempDir = null;

        if ($isRemoteDestination) {
            $extractTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_extract_', true);
            if (!@mkdir($extractTempDir, 0755, true) && !is_dir($extractTempDir)) {
                throw new CompressionException("Unable to create extraction directory: {$extractTempDir}");
            }
            $extractDestination = PathHelper::normalize($extractTempDir);
        } elseif (!FlysystemHelper::directoryExists($destination)) {
            FlysystemHelper::createDirectory($destination);
        }

        if ($this->executionStrategy !== ExecutionStrategy::PHP && $this->password === null && NativeOperationsAdapter::canUseNativeCompression() && !$isRemoteDestination) {
            $this->closeZip();
            $native = NativeOperationsAdapter::decompressZip($this->workingZipPath, $destination);
            if ($native['success']) {
                if (is_callable($this->progressCallback)) {
                    ($this->progressCallback)([
                        'operation' => 'decompress',
                        'path' => $this->zipFilePath,
                        'current' => 1,
                        'total' => 1,
                    ]);
                }
                $this->openZip();

                return $this;
            }

            if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
                throw new CompressionException("Native decompression failed for archive: {$this->zipFilePath}");
            }

            $this->openZip();
        }

        if ($this->password) {
            $this->zip->setPassword($this->password);
        }

        try {
            if (!$this->zip->extractTo($extractDestination)) {
                throw new CompressionException("Failed to extract ZIP archive.");
            }

            if ($isRemoteDestination) {
                $this->copyLocalDirectoryToFlysystem($extractDestination, $destination);
            }

            if (is_callable($this->progressCallback)) {
                $total = $this->zip->numFiles;
                for ($i = 0; $i < $total; $i++) {
                    ($this->progressCallback)([
                        'operation' => 'decompress',
                        'path' => (string) $this->zip->getNameIndex($i),
                        'current' => $i + 1,
                        'total' => $total,
                    ]);
                }
            }
        } finally {
            if ($extractTempDir !== null) {
                $this->cleanupLocalizedPath($extractTempDir);
            }
        }

        $this->log("Decompressed to: $destination");
        return $this;
    }


    /**
     * Returns the number of files in the current ZIP archive.
     *
     * @return int The number of files in the current ZIP archive.
     */
    public function fileCount(): int
    {
        $this->reopenIfNeeded();
        return $this->zip->numFiles;
    }


    /**
     * Returns an iterator over the files in the current ZIP archive.
     *
     * Yields each file in the archive as a string, in the order they appear in the archive.
     *
     * @return \Generator An iterator over the files in the current ZIP archive.
     */
    public function getFileIterator(): \Generator
    {
        $this->reopenIfNeeded();
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            yield $this->zip->getNameIndex($i);
        }
    }


    /**
     * Get an array of all the files in the current ZIP archive.
     *
     * The returned array contains the names of all the files in the archive,
     * in the order they appear in the archive.
     *
     * @return array An array of file names in the current ZIP archive.
     */
    public function listFiles(): array
    {
        $this->reopenIfNeeded();
        $files = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $files[] = $this->zip->getNameIndex($i);
        }
        return $files;
    }


    /**
     * Registers a callback to be called when a certain event occurs.
     *
     * Supported events are:
     *
     * - `beforeAdd`: Called before a file or directory is added to the ZIP archive.
     *   The callback will receive the path to the file or directory as its first
     *   argument, and the ZipArchive object as its second argument.
     *
     * - `afterAdd`: Called after a file or directory has been added to the ZIP archive.
     *   The callback will receive the path to the file or directory as its first
     *   argument, and the ZipArchive object as its second argument.
     *
     * - `beforeSave`: Called before the ZIP archive is saved to disk.
     *   The callback will receive the path to the file to be saved as its first
     *   argument, and the ZipArchive object as its second argument.
     *
     * - `afterSave`: Called after the ZIP archive has been saved to disk.
     *   The callback will receive the path to the file that was saved as its first
     *   argument, and the ZipArchive object as its second argument.
     *
     * @param string $event The name of the event to register the callback for.
     * @param callable $callback The callback to register.
     */
    public function registerHook(string $event, callable $callback): self
    {
        $this->hooks[$event][] = $callback;
        return $this;
    }

    /**
     * Close the ZIP archive.
     *
     * This method is a no-op if the archive is already closed.
     *
     * @return self
     */
    public function save(): self
    {
        $this->closeZip();
        return $this;
    }


    /**
     * Set the default path to use for decompression if no path is provided.
     *
     * This is a convenience method, as you can always provide a path when calling
     * `decompress()`.
     *
     * @param string $path The default path to use for decompression.
     * @return static
     */
    public function setDefaultDecompressionPath(string $path): self
    {
        $this->defaultDecompressionPath = $path;
        return $this;
    }


    /**
     * Sets the encryption algorithm for the ZIP archive.
     *
     * This method allows you to specify the encryption algorithm to be used
     * when encrypting the ZIP archive. Supported algorithms are AES-256 and AES-128.
     *
     * @param int $algorithm The encryption algorithm to set. Must be one of
     *     ZipArchive::EM_AES_256 or ZipArchive::EM_AES_128.
     * @return self
     * @throws CompressionException If an invalid encryption algorithm is specified.
     */
    public function setEncryptionAlgorithm(int $algorithm): self
    {
        if (!in_array($algorithm, [ZipArchive::EM_AES_256, ZipArchive::EM_AES_128], true)) {
            throw new CompressionException("Invalid encryption algorithm specified.");
        }

        $this->encryptionAlgorithm = $algorithm;
        return $this;
    }

    public function setExecutionStrategy(ExecutionStrategy $executionStrategy): self
    {
        $this->executionStrategy = $executionStrategy;

        return $this;
    }

    /**
     * Configure include/exclude glob patterns used during compression.
     */
    public function setGlobPatterns(array $includePatterns = [], array $excludePatterns = []): self
    {
        $this->includePatterns = array_values(array_filter(array_map('trim', $includePatterns), fn ($v) => $v !== ''));
        $this->excludePatterns = array_values(array_filter(array_map('trim', $excludePatterns), fn ($v) => $v !== ''));

        return $this;
    }

    /**
     * Configure ignore file names (e.g. .gitignore, .pathwiseignore) read from source root.
     */
    public function setIgnoreFileNames(array $ignoreFileNames): self
    {
        $this->ignoreFileNames = array_values(array_filter(array_map('trim', $ignoreFileNames), fn ($v) => $v !== ''));

        return $this;
    }


    /**
     * Sets a logger callable to be called when certain events occur.
     *
     * The callable will receive a string message as its first argument, and
     * the ZipArchive object as its second argument.
     *
     * @param callable $logger The logger callable. The callable should accept
     *   two arguments: the first is a string message, and the second is the
     *   ZipArchive object.
     *
     * @return self
     */
    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }


    /**
     * Set the password for the ZIP archive.
     *
     * @param string $password The password to encrypt the ZIP archive with.
     * @return self
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Register a progress callback for compress/decompress operations.
     */
    public function setProgressCallback(callable $progressCallback): self
    {
        $this->progressCallback = $progressCallback;

        return $this;
    }


    /**
     * Recursively adds files to the current ZIP archive.
     *
     * This method traverses the specified directory and adds files to the
     * ZIP archive. Directories are added as empty directories. If the
     * password is set, files are added with encryption.
     *
     * @param string $path The path to add files from.
     * @param ZipArchive $zip The ZIP archive to add files to.
     * @param string|null $baseDir The base directory to use for relative paths.
     */
    private function addFilesToZip(string $path, ZipArchive $zip, ?string $baseDir = null): void
    {
        $baseDir ??= $path;

        if (is_dir($path)) {
            $relativePath = $this->getRelativePath($path, $baseDir);
            if ($relativePath !== '' && !$this->shouldTraverseDirectory($relativePath)) {
                return;
            }
            if (!empty($relativePath)) {
                $zip->addEmptyDir($relativePath);
            }

            $entries = scandir($path);
            if ($entries === false) {
                throw new CompressionException("Failed to read directory: {$path}");
            }

            foreach ($entries as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->addFilesToZip($path . DIRECTORY_SEPARATOR . $file, $zip, $baseDir);
                }
            }
        } else {
            $relativePath = $this->getRelativePath($path, $baseDir);
            if ($relativePath === '') {
                $relativePath = basename($path);
            }
            if (!$this->shouldIncludePath($relativePath)) {
                return;
            }
            if ($this->password) {
                $zip->setPassword($this->password);
                $zip->addFile($path, $relativePath);
                $zip->setEncryptionName($relativePath, $this->encryptionAlgorithm);
            } else {
                $zip->addFile($path, $relativePath);
            }

            $this->advanceProgress('compress', $relativePath);
        }
    }


    /**
     * Recursively add files to the ZIP archive, filtering by extensions.
     *
     * This method traverses the specified directory and adds files to the
     * ZIP archive based on the provided file extensions. Directories are
     * added as empty directories if no matching files are found within them.
     * If a password is set, files are encrypted using the specified algorithm.
     *
     * @param string $path The path to the directory or file to add.
     * @param ZipArchive $zip The ZIP archive instance to add files to.
     * @param string|null $relativePath The relative path within the ZIP archive.
     * @param array $extensions An array of file extensions to filter by.
     */
    private function addFilesToZipWithFilter(string $path, ZipArchive $zip, ?string $relativePath, array $extensions): void
    {
        $relativePath ??= basename($path);
        $relativePath = $this->normalizeZipPath($relativePath);

        if (is_dir($path)) {
            if ($relativePath !== '' && !$this->shouldTraverseDirectory($relativePath)) {
                return;
            }
            $zip->addEmptyDir($relativePath);
            $entries = scandir($path);
            if ($entries === false) {
                throw new CompressionException("Failed to read directory: {$path}");
            }

            foreach ($entries as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->addFilesToZipWithFilter($path . DIRECTORY_SEPARATOR . $file, $zip, "$relativePath/$file", $extensions);
                }
            }
        } elseif ((empty($extensions) || in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions)) && $this->shouldIncludePath($relativePath)) {
            if ($this->password) {
                $zip->setPassword($this->password);
                $zip->addFile($path, $relativePath);
                $zip->setEncryptionName($relativePath, $this->encryptionAlgorithm);
            } else {
                $zip->addFile($path, $relativePath);
            }

            $this->advanceProgress('compress', $relativePath);
        }
    }

    private function advanceProgress(string $operation, string $path): void
    {
        if (!is_callable($this->progressCallback)) {
            return;
        }

        $this->progressCurrent++;
        ($this->progressCallback)([
            'operation' => $operation,
            'path' => $path,
            'current' => $this->progressCurrent,
            'total' => $this->progressTotal,
        ]);
    }

    private function cleanupDeferredLocalizedPaths(): void
    {
        if ($this->localizedCleanupPaths === []) {
            return;
        }

        foreach (array_keys($this->localizedCleanupPaths) as $path) {
            $this->cleanupLocalizedPath($path);
        }

        $this->localizedCleanupPaths = [];
    }

    private function cleanupLocalizedPath(?string $path): void
    {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }


    /**
     * Closes the current ZIP archive.
     *
     * If the archive is currently open, this method will close it using the
     * ZipArchive::close() method. The $isOpen flag is then set to false to
     * indicate that the archive is no longer open.
     */
    private function closeZip(): void
    {
        if ($this->isOpen) {
            $this->zip->close();
            $this->isOpen = false;
            $this->syncWorkingZipIfNeeded();
        }

        $this->cleanupDeferredLocalizedPaths();
    }

    private function copyLocalDirectoryToFlysystem(string $localSource, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localSource, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = $this->getRelativePath($item->getPathname(), $localSource);
            if ($relative === '') {
                continue;
            }

            $targetPath = PathHelper::join($destination, $relative);

            if ($item->isDir()) {
                FlysystemHelper::createDirectory($targetPath);
                continue;
            }

            $stream = fopen($item->getPathname(), 'rb');
            if (!is_resource($stream)) {
                throw new CompressionException("Unable to read extracted file: {$item->getPathname()}");
            }

            try {
                FlysystemHelper::writeStream($targetPath, $stream);
            } finally {
                fclose($stream);
            }
        }
    }

    private function countFilesForCompression(string $source, array $extensions = []): int
    {
        if (is_file($source)) {
            $relative = basename($source);
            if (!empty($extensions) && !in_array(pathinfo($source, PATHINFO_EXTENSION), $extensions, true)) {
                return 0;
            }
            return $this->shouldIncludePath($relative) ? 1 : 0;
        }

        if (!is_dir($source)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $relative = $this->getRelativePath($item->getPathname(), $source);
            if (!empty($extensions) && !in_array(pathinfo($item->getPathname(), PATHINFO_EXTENSION), $extensions, true)) {
                continue;
            }
            if ($this->shouldIncludePath($relative)) {
                $count++;
            }
        }

        return $count;
    }

    private function deferLocalizedCleanupPath(?string $path): void
    {
        if (!is_string($path) || $path === '') {
            return;
        }

        $this->localizedCleanupPaths[$path] = true;
    }

    /**
     * Build a ZIP-safe relative path.
     */
    private function getRelativePath(string $path, string $baseDir): string
    {
        $normalizedPath = str_replace('\\', '/', PathHelper::normalize($path));
        $normalizedBase = rtrim(str_replace('\\', '/', PathHelper::normalize($baseDir)), '/');

        if ($normalizedPath === $normalizedBase) {
            return '';
        }

        if (str_starts_with($normalizedPath, $normalizedBase . '/')) {
            return substr($normalizedPath, strlen($normalizedBase) + 1);
        }

        return ltrim($normalizedPath, '/');
    }

    private function initializeProgress(string $source, array $extensions = []): void
    {
        $this->progressCurrent = 0;
        $this->progressTotal = $this->countFilesForCompression($source, $extensions);
    }

    private function isRemotePath(string $path): bool
    {
        return PathHelper::hasScheme($path) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($path));
    }

    private function loadIgnorePatterns(string $source): void
    {
        $this->ignorePatterns = [];

        if (!is_dir($source)) {
            return;
        }

        foreach ($this->ignoreFileNames as $fileName) {
            $ignoreFilePath = PathHelper::join($source, $fileName);
            if (!FlysystemHelper::fileExists($ignoreFilePath)) {
                continue;
            }

            $lines = preg_split('/\R/', FlysystemHelper::read($ignoreFilePath)) ?: [];
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $pattern = trim($line);
                if ($pattern === '' || str_starts_with($pattern, '#')) {
                    continue;
                }
                $this->ignorePatterns[] = str_replace('\\', '/', ltrim($pattern, './'));
            }
        }
    }

    private function localizeCompressionSource(string $source, ?string &$cleanupPath = null): string
    {
        $normalizedSource = PathHelper::normalize($source);
        $cleanupPath = null;

        if (!PathHelper::hasScheme($normalizedSource) && (is_file($normalizedSource) || is_dir($normalizedSource))) {
            return $normalizedSource;
        }

        if (FlysystemHelper::fileExists($normalizedSource)) {
            $tempFile = tempnam(sys_get_temp_dir(), 'pathwise_src_');
            if ($tempFile === false) {
                throw new CompressionException("Unable to localize source path: {$source}");
            }

            $stream = FlysystemHelper::readStream($normalizedSource);
            $target = fopen($tempFile, 'wb');
            if (!is_resource($stream) || !is_resource($target)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                @unlink($tempFile);
                throw new CompressionException("Unable to localize source path: {$source}");
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            $cleanupPath = PathHelper::normalize($tempFile);

            return $cleanupPath;
        }

        if (FlysystemHelper::directoryExists($normalizedSource)) {
            $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_src_dir_', true);
            if (!@mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
                throw new CompressionException("Unable to localize source path: {$source}");
            }

            $cleanupPath = PathHelper::normalize($tempDir);
            $this->materializeDirectoryToLocal($normalizedSource, $cleanupPath);

            return $cleanupPath;
        }

        throw new CompressionException("Source path does not exist: {$source}");
    }


    /**
     * Logs a message using the registered logger callback.
     *
     * If a logger function is set, this method will invoke it
     * with the provided message.
     *
     * @param string $message The message to log.
     */
    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($message);
        }
    }

    private function materializeDirectoryToLocal(string $sourcePath, string $localDirectory): void
    {
        [, $baseLocation] = FlysystemHelper::resolveDirectory($sourcePath);
        $base = trim(str_replace('\\', '/', $baseLocation), '/');

        foreach (FlysystemHelper::listContents($sourcePath, true) as $item) {
            $itemPath = trim((string) ($item['path'] ?? ''), '/');
            if ($itemPath === '') {
                continue;
            }

            $relative = $base !== '' && str_starts_with($itemPath, $base . '/')
                ? substr($itemPath, strlen($base) + 1)
                : ($itemPath === $base ? '' : $itemPath);
            if ($relative === '') {
                continue;
            }

            $localTarget = PathHelper::join($localDirectory, $relative);
            if (($item['type'] ?? null) === 'dir') {
                if (!is_dir($localTarget)) {
                    @mkdir($localTarget, 0755, true);
                }
                continue;
            }

            $localParent = dirname($localTarget);
            if (!is_dir($localParent)) {
                @mkdir($localParent, 0755, true);
            }

            $resolvedPath = PathHelper::join($sourcePath, $relative);
            $stream = FlysystemHelper::readStream($resolvedPath);
            $target = fopen($localTarget, 'wb');
            if (!is_resource($stream) || !is_resource($target)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new CompressionException("Unable to read source path: {$resolvedPath}");
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);
        }
    }

    private function normalizeZipPath(string $path): string
    {
        $hadTrailingSlash = str_ends_with(str_replace('\\', '/', $path), '/');
        $normalized = ltrim(str_replace('\\', '/', PathHelper::normalize($path)), '/');

        if ($hadTrailingSlash && $normalized !== '' && !str_ends_with($normalized, '/')) {
            $normalized .= '/';
        }

        return $normalized;
    }


    /**
     * Opens the ZIP archive with the specified flags.
     *
     * This method attempts to open the ZIP archive located at the specified
     * file path using the provided flags. If the archive cannot be opened,
     * an exception is thrown with the corresponding error code.
     *
     * @param int $flags Optional flags to use when opening the ZIP archive.
     * @throws CompressionException if the ZIP archive cannot be opened.
     */
    private function openZip(int $flags = 0): void
    {
        if (($flags & ZipArchive::CREATE) === 0 && !FlysystemHelper::fileExists($this->zipFilePath)) {
            throw new CompressionException("ZIP archive does not exist: {$this->zipFilePath}");
        }

        $result = $this->zip->open($this->workingZipPath, $flags);
        if ($result !== true) {
            throw new CompressionException("Failed to open ZIP archive at {$this->zipFilePath}. Error: $result");
        }
        $this->isOpen = true;
    }


    /**
     * Reopen the ZIP archive if it has been closed.
     *
     * If the archive is already open, this method is a no-op.
     * @throws CompressionException
     */
    private function reopenIfNeeded(): void
    {
        if (!$this->isOpen) {
            $this->openZip();
        }
    }

    private function resolveWorkingZipPath(bool $create): string
    {
        if (!$this->isRemotePath($this->zipFilePath)) {
            return PathHelper::normalize($this->zipFilePath);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pathwise_zip_');
        if ($tempFile === false) {
            throw new CompressionException("Unable to allocate temporary ZIP path for {$this->zipFilePath}");
        }

        $this->cleanupWorkingZipPath = true;
        $this->syncWorkingZipOnClose = true;
        $normalizedTemp = PathHelper::normalize($tempFile);

        if (FlysystemHelper::fileExists($this->zipFilePath)) {
            $source = FlysystemHelper::readStream($this->zipFilePath);
            $target = fopen($normalizedTemp, 'wb');
            if (!is_resource($source) || !is_resource($target)) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new CompressionException("Unable to read ZIP archive: {$this->zipFilePath}");
            }

            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
        } elseif (!$create) {
            @unlink($normalizedTemp);
            throw new CompressionException("ZIP archive does not exist: {$this->zipFilePath}");
        } else {
            // Avoid opening an empty placeholder file as a ZIP archive (deprecated behavior in PHP 8.4+).
            @unlink($normalizedTemp);
        }

        return $normalizedTemp;
    }

    private function shouldAttemptNativeCompression(): bool
    {
        if ($this->executionStrategy === ExecutionStrategy::PHP) {
            return false;
        }

        // Native path currently targets whole-source archive operations only.
        return $this->password === null
            && $this->includePatterns === []
            && $this->excludePatterns === []
            && $this->ignorePatterns === []
            && $this->hooks === [];
    }

    private function shouldIncludePath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if ($relativePath === '') {
            return true;
        }

        if ($this->includePatterns !== []) {
            $matchesInclude = false;
            foreach ($this->includePatterns as $pattern) {
                if (fnmatch($pattern, $relativePath)) {
                    $matchesInclude = true;
                    break;
                }
            }
            if (!$matchesInclude) {
                return false;
            }
        }

        foreach (array_merge($this->excludePatterns, $this->ignorePatterns) as $pattern) {
            if (fnmatch($pattern, $relativePath)) {
                return false;
            }
        }

        return true;
    }

    private function shouldTraverseDirectory(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', trim($relativePath, '/'));
        if ($normalized === '') {
            return true;
        }

        foreach (array_merge($this->excludePatterns, $this->ignorePatterns) as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if (fnmatch(rtrim($pattern, '/'), $normalized) || fnmatch(rtrim($pattern, '/') . '/*', $normalized . '/x')) {
                return false;
            }
        }

        return true;
    }

    private function syncWorkingZipIfNeeded(): void
    {
        if (!$this->syncWorkingZipOnClose || !is_file($this->workingZipPath)) {
            return;
        }

        $stream = fopen($this->workingZipPath, 'rb');
        if (!is_resource($stream)) {
            throw new CompressionException("Unable to stream ZIP archive: {$this->workingZipPath}");
        }

        try {
            FlysystemHelper::writeStream($this->zipFilePath, $stream);
        } finally {
            fclose($stream);
        }
    }


    /**
     * Triggers all registered hooks for a specified event.
     *
     * This method iterates over the registered callbacks for the given event
     * and executes each callback with the provided arguments. If no hooks are
     * registered for the event, the method does nothing.
     *
     * @param string $event The name of the event to trigger hooks for.
     * @param mixed ...$args Arguments to pass to the callback functions.
     */
    private function triggerHook(string $event, mixed ...$args): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            $callback(...$args);
        }
    }
}
