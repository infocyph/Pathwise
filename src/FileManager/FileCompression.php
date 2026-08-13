<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Exceptions\MissingExtensionException;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\FileManager\Concerns\FileCompressionArchiveConcern;
use Infocyph\Pathwise\FileManager\Concerns\FileCompressionRuntimeConcern;
use Infocyph\Pathwise\FileManager\Concerns\FsConcern;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Security\ZipEntryValidator;
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

    private float $maxCompressionRatio = ZipEntryValidator::DEFAULT_MAX_COMPRESSION_RATIO;

    private int $maxEntries = ZipEntryValidator::DEFAULT_MAX_ENTRIES;

    private int $maxEntryUncompressedBytes = ZipEntryValidator::DEFAULT_MAX_ENTRY_UNCOMPRESSED_BYTES;

    private int $maxTotalUncompressedBytes = ZipEntryValidator::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES;

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
        if (!extension_loaded('zip')) {
            throw new MissingExtensionException('ZIP operations require ext-zip.');
        }

        $this->zip = new ZipArchive();
        $this->workingZipPath = $this->resolveWorkingZipPath($create);

        $this->encryptionAlgorithm = ZipArchive::EM_AES_256;

        $flags = $create ? ZipArchive::CREATE | ZipArchive::OVERWRITE : 0;
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
     */
    public function addFile(string $filePath, ?string $zipPath = null): self
    {
        $this->reopenIfNeeded();
        if (!FlysystemHelper::fileExists($filePath)) {
            throw new CompressionException("File does not exist: $filePath");
        }
        $zipPath ??= basename($filePath);
        $zipPath = $this->normalizeZipPath($zipPath);
        $this->log("Adding file: $filePath");
        $this->addFileToArchive($filePath, $zipPath);

        $this->progressTotal = max(1, $this->progressTotal);
        $this->advanceProgress('compress', $zipPath);

        return $this;
    }

    /**
     * Batch add multiple files to the current ZIP archive.
     *
     * @param array<int|string, string|null> $files An associative array of file paths mapped to their
     *                                              desired paths inside the ZIP archive. If a value is not provided for
     *                                              a key, the basename of the file will be used as the path in the ZIP
     *                                              archive.
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
        ZipEntryValidator::validateArchive($this->zip, $destination);
        foreach ($files as $zipPath => $localPath) {
            $zipPath = ZipEntryValidator::validate($zipPath, $destination);
            $localPath = ZipEntryValidator::validate($localPath, $destination);
            $targetPath = PathHelper::join($destination, $localPath);

            if (str_ends_with($zipPath, '/')) {
                if (!FlysystemHelper::directoryExists($targetPath)) {
                    FlysystemHelper::createDirectory($targetPath);
                }

                continue;
            }

            $stream = $this->zip->getStream($zipPath);
            if (!is_resource($stream)) {
                throw new CompressionException("File not found in ZIP archive: $zipPath.");
            }

            $targetDir = dirname($targetPath);
            if (!FlysystemHelper::directoryExists($targetDir)) {
                FlysystemHelper::createDirectory($targetDir);
            }

            try {
                FlysystemHelper::writeStream($targetPath, $stream);
            } finally {
                fclose($stream);
            }

            $this->advanceProgress('decompress', $zipPath);
        }

        return $this;
    }

    /**
     * Compress a file or directory into the ZIP archive.
     *
     * @param string $source The path to the file or directory to compress.
     */
    public function compress(string $source): self
    {
        if (
            FlysystemHelper::directoryExists($source)
            && FlysystemHelper::isSameOrDescendant($source, $this->zipFilePath)
        ) {
            throw new CompressionException('The destination archive must be outside the source directory.');
        }

        $this->reopenIfNeeded();
        $resolvedSource = $this->prepareCompressionSource($source);

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            $this->assertNativeCompressionSupported($source);
        }

        if ($this->shouldAttemptNativeCompression() && NativeOperationsAdapter::canUseNativeZipCompression()) {
            $this->closeZip();
            $native = NativeOperationsAdapter::compressToZip($resolvedSource, $this->workingZipPath);
            if ($native->success) {
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
                throw new NativeExecutionException(
                    "Native compression failed with exit code {$native->exitCode}: " . implode("\n", $native->output),
                    $native,
                );
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
     */
    public function compressWithFilter(string $source, array $extensions = []): self
    {
        $this->reopenIfNeeded();
        $resolvedSource = $this->prepareCompressionSource($source);

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
     * @throws CompressionException
     */
    public function decompress(?string $destination = null): self
    {
        $this->reopenIfNeeded();
        $destination = $this->resolveDecompressionDestination($destination);
        $validatedEntries = $this->validateArchiveForExtraction($destination);
        ['extractDestination' => $extractDestination, 'extractTempDir' => $extractTempDir, 'isRemote' => $isRemoteDestination] = $this->prepareExtractionDestination($destination);

        if ($this->attemptNativeDecompression($destination, $isRemoteDestination)) {
            return $this;
        }

        $this->applyArchivePassword();

        try {
            $this->extractArchive($validatedEntries, $extractDestination, $destination, $isRemoteDestination);
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
     * Check the integrity of the current ZIP archive.
     *
     * This function checks the status of the current ZIP archive and returns
     * true if the archive is valid and false otherwise.
     *
     * @return bool True if the archive is valid, false otherwise.
     */
    public function hasNoReportedArchiveErrors(): bool
    {
        $this->reopenIfNeeded();

        return $this->zip->status === ZipArchive::ER_OK;
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
     *   The callback receives the source path and archive entry path.
     *
     * - `afterAdd`: Called after a file or directory has been added to the ZIP archive.
     *   The callback receives the source path and archive entry path.
     *
     * - `beforeSave`: Called before the ZIP archive is saved to disk.
     *   The callback receives the archive path.
     *
     * - `afterSave`: Called after the ZIP archive has been saved to disk.
     *   The callback receives the archive path.
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
     * Configure extraction resource limits. A value of zero disables that limit.
     */
    public function setExtractionLimits(
        int $maxEntries = ZipEntryValidator::DEFAULT_MAX_ENTRIES,
        int $maxEntryUncompressedBytes = ZipEntryValidator::DEFAULT_MAX_ENTRY_UNCOMPRESSED_BYTES,
        int $maxTotalUncompressedBytes = ZipEntryValidator::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES,
        float $maxCompressionRatio = ZipEntryValidator::DEFAULT_MAX_COMPRESSION_RATIO,
    ): self {
        ZipEntryValidator::validateArchiveLimits(
            $maxEntries,
            $maxEntryUncompressedBytes,
            $maxTotalUncompressedBytes,
            $maxCompressionRatio,
        );
        $this->maxEntries = $maxEntries;
        $this->maxEntryUncompressedBytes = $maxEntryUncompressedBytes;
        $this->maxTotalUncompressedBytes = $maxTotalUncompressedBytes;
        $this->maxCompressionRatio = $maxCompressionRatio;

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
        if ($password === '') {
            throw new CompressionException('ZIP passwords must not be empty.');
        }
        if (!defined('ZipArchive::EM_AES_256')) {
            throw new CompressionException('AES ZIP encryption is unavailable in the installed ZIP extension.');
        }

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

    private function assertNativeCompressionSupported(string $source): void
    {
        if (!FlysystemHelper::isLocalPath($source) || !FlysystemHelper::isLocalPath($this->zipFilePath)) {
            throw new UnsupportedStorageOperationException(
                'Native compression requires local source and archive paths.',
            );
        }
        if (!$this->shouldAttemptNativeCompression()) {
            throw new NativeExecutionException('Native compression does not support the selected archive options.');
        }
        if (!NativeOperationsAdapter::canUseNativeZipCompression()) {
            throw new NativeExecutionException('Native ZIP compression executables are unavailable.');
        }
    }

    private function prepareCompressionSource(string $source): string
    {
        $cleanupPath = null;
        $resolvedSource = $this->localizeCompressionSource($source, $cleanupPath);
        $this->deferLocalizedCleanupPath($cleanupPath);

        return $resolvedSource;
    }
}
