<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\FileManager\Concerns\FileCompressionArchiveConcern;
use Infocyph\Pathwise\FileManager\Concerns\FileCompressionRuntimeConcern;
use Infocyph\Pathwise\FileManager\Concerns\FsConcern;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use ZipArchive;

/**
 * @phpstan-type HookCallback callable(mixed...): mixed
 * @phpstan-type ExtractionDestination array{
 *     extractDestination: string,
 *     extractTempDir: string|null,
 *     isRemote: bool
 * }
 */
class FileCompression
{
    use FileCompressionArchiveConcern;
    use FileCompressionRuntimeConcern;
    use FsConcern;

    private readonly ZipArchive $zip;

    private bool $cleanupWorkingZipPath = false;

    private ?string $defaultDecompressionPath = null;

    private int $encryptionAlgorithm;

    /** @var list<string> */
    private array $excludePatterns = [];

    private ExecutionStrategy $executionStrategy = ExecutionStrategy::PHP;

    /** @var array<string, list<HookCallback>> */
    private array $hooks = [];

    /** @var list<string> */
    private array $ignoreFileNames = ['.pathwiseignore', '.gitignore'];

    /** @var list<string> */
    private array $ignorePatterns = [];

    /** @var list<string> */
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
                $this->unlinkPathSilently($this->workingZipPath);
            }
        }
    }

    /**
     * Adds a single file to the current ZIP archive.
     *
     * @param string $filePath The path to the file to be added.
     * @param string|null $zipPath The path in the ZIP archive where the file should be stored.
     *                             If not provided, the file will be stored in the root directory of the ZIP file,
     *                             with its original name.
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
        $this->addFileToArchive($filePath, $zipPath);

        $this->progressTotal = max(1, $this->progressTotal);
        $this->advanceProgress('compress', $zipPath);

        $this->triggerHook('afterAdd', $filePath);

        return $this;
    }

    /**
     * Batch add multiple files to the current ZIP archive.
     *
     * @param array<int|string, string|null> $files An associative array of file paths mapped to their
     *                                              desired paths inside the ZIP archive. If a value is not provided for
     *                                              a key, the basename of the file will be used as the path in the ZIP
     *                                              archive.
     * @return $this
     */
    public function batchAddFiles(array $files): self
    {
        $this->reopenIfNeeded();
        $this->log('Batch adding files.');
        foreach ($files as $filePath => $zipPath) {
            if (is_int($filePath)) {
                if (!is_string($zipPath)) {
                    throw new CompressionException('Invalid file path provided for batch add.');
                }

                $this->addFile($zipPath);

                continue;
            }

            $this->addFile($filePath, $zipPath);
        }

        return $this;
    }

    /**
     * Batch extract multiple files from the current ZIP archive.
     *
     * @param array<string, string> $files An associative array mapping ZIP paths to local paths.
     * @param string $destination The destination directory to extract to.
     *
     *
     * @throws CompressionException If any of the files fail to extract.
     */
    public function batchExtractFiles(array $files, string $destination): self
    {
        $this->reopenIfNeeded();
        $destination = PathHelper::normalize($destination);
        if (!FlysystemHelper::directoryExists($destination)) {
            FlysystemHelper::createDirectory($destination);
        }
        $this->log('Batch extracting files.');
        $this->progressCurrent = 0;
        $this->progressTotal = count($files);
        foreach ($files as $zipPath => $localPath) {
            $zipPath = $this->normalizeZipPath($zipPath);
            $localPath = ltrim(PathHelper::normalize($localPath), DIRECTORY_SEPARATOR);
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
     * @param list<string> $extensions An array of file extensions to include.
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
        $destination = $this->resolveDecompressionDestination($destination);
        ['extractDestination' => $extractDestination, 'extractTempDir' => $extractTempDir, 'isRemote' => $isRemoteDestination] = $this->prepareExtractionDestination($destination);

        if ($this->attemptNativeDecompression($destination, $isRemoteDestination)) {
            return $this;
        }

        $this->applyArchivePassword();

        try {
            $this->extractArchive($extractDestination, $destination, $isRemoteDestination);
            $this->emitDecompressionProgress();
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
     * @return \Generator<int, string> An iterator over the files in the current ZIP archive.
     */
    public function getFileIterator(): \Generator
    {
        $this->reopenIfNeeded();
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }

            yield $name;
        }
    }

    /**
     * Get an array of all the files in the current ZIP archive.
     *
     * The returned array contains the names of all the files in the archive,
     * in the order they appear in the archive.
     *
     * @return list<string> An array of file names in the current ZIP archive.
     */
    public function listFiles(): array
    {
        $this->reopenIfNeeded();
        $files = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }
            $files[] = $name;
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
     *                       ZipArchive::EM_AES_256 or ZipArchive::EM_AES_128.
     * @throws CompressionException If an invalid encryption algorithm is specified.
     */
    public function setEncryptionAlgorithm(int $algorithm): self
    {
        if (!in_array($algorithm, [ZipArchive::EM_AES_256, ZipArchive::EM_AES_128], true)) {
            throw new CompressionException('Invalid encryption algorithm specified.');
        }

        $this->encryptionAlgorithm = $algorithm;

        return $this;
    }

    /**
     * Set the execution strategy for compression operations.
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
     * Configure include/exclude glob patterns used during compression.
     *
     * @param list<string> $includePatterns Patterns to include in compression.
     * @param list<string> $excludePatterns Patterns to exclude from compression.
     * @return self This instance for method chaining.
     */
    public function setGlobPatterns(array $includePatterns = [], array $excludePatterns = []): self
    {
        $this->includePatterns = $this->normalizeNonEmptyStrings($includePatterns);
        $this->excludePatterns = $this->normalizeNonEmptyStrings($excludePatterns);

        return $this;
    }

    /**
     * Configure ignore file names (e.g. .gitignore, .pathwiseignore) read from source root.
     *
     * @param list<string> $ignoreFileNames Array of ignore file names.
     * @return self This instance for method chaining.
     */
    public function setIgnoreFileNames(array $ignoreFileNames): self
    {
        $this->ignoreFileNames = $this->normalizeNonEmptyStrings($ignoreFileNames);

        return $this;
    }

    /**
     * Sets a logger callable to be called when certain events occur.
     *
     * The callable will receive a string message as its first argument, and
     * the ZipArchive object as its second argument.
     *
     * @param callable $logger The logger callable. The callable should accept
     *                         two arguments: the first is a string message, and the second is the
     *                         ZipArchive object.
     * @return self This instance for method chaining.
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
     * @return self This instance for method chaining.
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Register a progress callback for compress/decompress operations.
     *
     * @param callable $progressCallback Callback receiving progress array with operation, path, current, and total.
     * @return self This instance for method chaining.
     */
    public function setProgressCallback(callable $progressCallback): self
    {
        $this->progressCallback = $progressCallback;

        return $this;
    }
}
