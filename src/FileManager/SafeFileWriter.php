<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Countable;
use DateTime;
use DateTimeInterface;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\MissingExtensionException;
use Infocyph\Pathwise\FileManager\Concerns\SafeFileWriterWriteConcern;
use Infocyph\Pathwise\Integration\Runwire\RunwireScopedConcern;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Pathwise\Utils\StreamTransferHelper;
use JsonSerializable;
use SimpleXMLElement;
use SplFileObject;
use Stringable;

class SafeFileWriter implements Countable, Stringable, JsonSerializable
{
    use RunwireScopedConcern;
    use SafeFileWriterWriteConcern;

    private ?string $atomicTempFilePath = null;

    private bool $atomicWriteEnabled = false;

    private bool $cleanupLocalWorkingPath = false;

    private ?SplFileObject $file = null;

    private ?int $heldLockType = null;

    private ?string $localWorkingPath = null;

    private int $writeCount = 0;

    private bool $writeInitialized = false;

    /** @var array<string, int> */
    private array $writeTypesCount = [];

    /**
     * Creates a new SafeFileWriter instance.
     *
     * @param string $filename The name of the file to write to.
     * @param bool $append Whether to append to the existing file or truncate it.
     */
    public function __construct(private readonly string $filename, private readonly bool $append = false) {}

    /**
     * Closes the file and releases any system resources associated with it.
     *
     * Called automatically when the object is no longer referenced.
     * @throws FileAccessException
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // Never throw from destructors.
        } finally {
            if (is_string($this->atomicTempFilePath) && is_file($this->atomicTempFilePath)) {
                $this->unlinkPathSilently($this->atomicTempFilePath);
            }
            if ($this->cleanupLocalWorkingPath && is_string($this->localWorkingPath) && is_file($this->localWorkingPath)) {
                $this->unlinkPathSilently($this->localWorkingPath);
            }
        }
    }

    /**
     * Converts the SafeFileWriter object to a string representation.
     *
     * This method returns a string that includes the filename, current file size in bytes,
     * and the total number of write operations performed.
     *
     * @return string A descriptive string of the SafeFileWriter object.
     */
    public function __toString(): string
    {
        return sprintf(
            'SafeFileWriter [File: %s, Size: %d bytes, Writes: %d]',
            $this->filename,
            $this->getSize(),
            $this->writeCount,
        );
    }

    /**
     * Closes the file handle.
     *
     * This method releases the lock on the file if it has not already been
     * released, and then unsets the file handle to free up resources.
     * @throws FileAccessException
     */
    public function close(): void
    {
        if ($this->file === null) {
            return;
        }

        $this->unlock();
        $this->file = null;
        $this->finalizeAtomicWrite();
        $this->syncWorkingCopyBack();
        $this->writeInitialized = false;
    }

    /**
     * Returns the total number of write operations performed.
     *
     * @return int The total number of write operations performed.
     */
    public function count(): int
    {
        return $this->writeCount;
    }

    /**
     * Enable or disable atomic local replacement mode.
     *
     * Atomic mode writes to a temporary sibling and publishes it with one local
     * filesystem rename. It is deliberately unavailable for append mode and for
     * adapter-backed paths because Pathwise cannot promise adapter-level atomic
     * replacement semantics.
     *
     * @throws FileAccessException If atomic mode cannot be guaranteed.
     */
    public function enableAtomicWrite(bool $enabled = true): self
    {
        if ($enabled && $this->append) {
            throw new FileAccessException('Atomic write mode is not supported in append mode.');
        }
        if ($enabled && $this->isRemoteTarget()) {
            throw new FileAccessException('Atomic write mode requires a direct-local filesystem path.');
        }

        $this->atomicWriteEnabled = $enabled;

        return $this;
    }

    /**
     * Flushes the output to the file.
     *
     * This method forces any buffered output to be written to the underlying
     * file resource, ensuring that all data is physically stored on the disk.
     */
    public function flush(): void
    {
        $this->initiate($this->append ? 'a' : 'w');
        $this->file?->fflush();
    }

    /**
     * Gets the creation date of the file.
     *
     * @return DateTime The creation date of the file.
     */
    public function getCreationDate(): ?DateTime
    {
        return $this->resolveFileDate(
            static fn(string $path): int => (int) filectime($path),
            false,
        );
    }

    /**
     * Gets the last modification date of the file.
     *
     * @return DateTime The last modification date of the file.
     */
    public function getModificationDate(): ?DateTime
    {
        return $this->resolveFileDate(
            static fn(string $path): int => (int) filemtime($path),
            true,
        );
    }

    /**
     * Gets the size of the file in bytes.
     *
     * @return int The size of the file in bytes.
     */
    public function getSize(): int
    {
        $target = $this->getActiveOrFinalPath();
        if (is_file($target)) {
            $size = filesize($target);

            return is_int($size) ? $size : 0;
        }

        if (FlysystemHelper::fileExists($this->filename)) {
            return FlysystemHelper::size($this->filename);
        }

        return 0;
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array with the following keys:
     * - `filename`: The name of the file being written.
     * - `size`: The size of the file in bytes.
     * - `writes`: The total number of writes executed.
     * - `writeTypesCount`: An associative array with counts of each type of write.
     * - `modificationDate`: The last modification date in ISO 8601 format.
     * - `creationDate`: The creation date in ISO 8601 format.
     *
     * @return array<string, mixed> The associative array to be JSON serialized.
     */
    public function jsonSerialize(): array
    {
        return [
            'filename' => $this->filename,
            'size' => $this->getSize(),
            'writes' => $this->writeCount,
            'writeTypesCount' => $this->writeTypesCount,
            'modificationDate' => $this->getModificationDate()?->format(DateTimeInterface::ATOM),
            'creationDate' => $this->getCreationDate()?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Attempts to acquire a lock, with optional retry mechanism.
     *
     * @param int $lockType Lock type (LOCK_EX for exclusive, LOCK_SH for shared).
     * @param bool $waitForLock Whether to wait for the lock by retrying.
     * @param int $retries Number of retries to attempt if $waitForLock is true.
     * @param int $delay Delay between retries in milliseconds (used only if $waitForLock is true).
     * @throws FileAccessException If lock could not be acquired.
     */
    public function lock(int $lockType = LOCK_EX, bool $waitForLock = false, int $retries = 5, int $delay = 200): void
    {
        $this->checkpointRunwire();
        if (!in_array($lockType, [LOCK_EX, LOCK_SH], true)) {
            throw new FileAccessException("Invalid lock type for file {$this->filename}.");
        }
        if ($retries < 1 || $delay < 0 || $delay > 60_000) {
            throw new FileAccessException('Lock retries and delay must be finite and non-negative.');
        }
        if ($this->heldLockType === $lockType) {
            return;
        }

        // flock conversion can release the old lock even on failure; keep ownership explicit.
        $this->unlock();

        $this->initiate($this->append ? 'a' : 'c+');
        $this->acquireLock($this->requireFileHandle(), $lockType, $waitForLock ? $retries : 1, $waitForLock ? $delay : 0);
    }

    /**
     * Truncates the file to the specified size.
     *
     * If the size is not specified, the file is truncated to 0 bytes.
     * @param int $size The size to truncate to. Defaults to 0.
     */
    public function truncate(int $size = 0): void
    {
        $this->initiate($this->append ? 'a' : 'w');
        $this->file?->ftruncate($size);
        $this->writeInitialized = true;
    }

    /**
     * Releases the lock on the file.
     *
     * @throws FileAccessException If unlock fails.
     */
    public function unlock(): void
    {
        if ($this->heldLockType !== null && $this->file && !$this->file->flock(LOCK_UN)) {
            throw new FileAccessException("Failed to release lock on file {$this->filename}.");
        }
        $this->heldLockType = null;
    }

    /**
     * Verify the file's checksum against an expected value.
     *
     * @param string $expectedChecksum The expected checksum.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return bool True if the checksum matches, false otherwise.
     * @throws FileAccessException If the algorithm is not supported.
     */
    public function verifyChecksum(string $expectedChecksum, string $algorithm = 'sha256'): bool
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new FileAccessException("Unsupported checksum algorithm: {$algorithm}");
        }

        $path = $this->getActiveOrFinalPath();
        if (is_file($path)) {
            $fileHash = hash_file($algorithm, $path);

            return is_string($fileHash) && hash_equals($expectedChecksum, $fileHash);
        }

        $fileHash = FlysystemHelper::checksum($this->filename, $algorithm);

        return is_string($fileHash) && hash_equals($expectedChecksum, $fileHash);
    }

    /**
     * Write content and verify checksum against the persisted file.
     *
     * @throws FileAccessException
     */
    public function writeAndVerify(string $content, string $algorithm = 'sha256'): self
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new FileAccessException("Unsupported checksum algorithm: {$algorithm}");
        }

        $this->initiate('w');
        $this->truncate(0);
        $this->writeBinary($content);
        $this->flush();

        if ($this->atomicWriteEnabled) {
            $this->close();
        } elseif ($this->isRemoteTarget()) {
            $this->syncWorkingCopyBack();
        }

        $fileHash = $this->isRemoteTarget()
            ? FlysystemHelper::checksum($this->filename, $algorithm)
            : hash_file($algorithm, $this->filename);
        if (!is_string($fileHash) || !hash_equals(hash($algorithm, $content), $fileHash)) {
            throw new FileAccessException("Checksum verification failed for {$this->filename}.");
        }

        return $this;
    }

    public function writeBinary(string $data): int
    {
        return $this->performWrite('binary', fn(): int|false => $this->writeBinaryData($data));
    }

    public function writeCharacters(string $characters): int
    {
        return $this->performWrite('characters', fn(): int|false => $this->writeCharacterData($characters));
    }

    /** @param list<string|int|float|bool|null> $row */
    public function writeCsv(array $row, string $separator = ',', string $enclosure = '"', string $escape = '\\'): int
    {
        return $this->performWrite('csv', fn(): int|false => $this->writeCsvRow($row, $separator, $enclosure, $escape));
    }

    /**
     * @param list<string|int|float|bool|null> $data
     * @param list<int> $widths
     */
    public function writeFixedWidth(array $data, array $widths): int
    {
        if ($widths === [] || array_any($widths, static fn(int $width): bool => $width < 1)) {
            throw new FileAccessException('Fixed-width definitions must be positive integers.');
        }

        return $this->performWrite('fixed-width', fn(): int|false => $this->writeFixedWidthData($data, $widths));
    }

    public function writeJson(mixed $data, bool $prettyPrint = false): int
    {
        return $this->performWrite('json', fn(): int|false => $this->writeJsonLineData($data, $prettyPrint));
    }

    /** @param array<int|string, mixed> $data */
    public function writeJsonArray(array $data, bool $prettyPrint = false): int
    {
        return $this->performWrite('json-array', fn(): int|false => $this->writeJsonArrayData($data, $prettyPrint));
    }

    public function writeLine(string $content): int
    {
        return $this->performWrite('line', fn(): int|false => $this->writeLineData($content));
    }

    public function writeMatchingLine(string $content, string $pattern): int
    {
        return $this->performWrite('matching-line', fn(): int|false => $this->writeMatchingLineData($content, $pattern));
    }

    public function writeSerialized(mixed $data): int
    {
        return $this->performWrite('serialized', fn(): int|false => $this->writeSerializedData($data));
    }

    public function writeXml(SimpleXMLElement $element): int
    {
        if (!extension_loaded('simplexml')) {
            throw new MissingExtensionException('XML writing requires ext-simplexml.');
        }

        return $this->performWrite('xml', fn(): int|false => $this->writeXmlData($element));
    }

    private function acquireLock(SplFileObject $file, int $lockType, int $attempts, int $delay): void
    {
        $nonBlockingMode = $lockType === LOCK_EX ? LOCK_EX | LOCK_NB : LOCK_SH | LOCK_NB;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->checkpointRunwire();
            if ($file->flock($nonBlockingMode)) {
                $this->heldLockType = $lockType;
                $this->initializeLockedWrite($file, $lockType);

                return;
            }
            if ($attempt + 1 < $attempts && $delay > 0) {
                $this->sleepRunwire($delay / 1_000);
            }
        }

        throw new FileAccessException("Failed to acquire lock on file {$this->filename} after {$attempts} attempts.");
    }

    private function createLocalTempFile(string $prefix, string $directory): string
    {
        $tempFile = tempnam($directory, $prefix);
        if ($tempFile === false) {
            throw new FileAccessException("Unable to create temporary file for {$this->filename}");
        }

        return PathHelper::normalize($tempFile);
    }

    private function finalizeAtomicWrite(): void
    {
        if (!$this->atomicWriteEnabled || $this->atomicTempFilePath === null) {
            return;
        }

        if (!is_file($this->atomicTempFilePath)) {
            $this->atomicTempFilePath = null;

            return;
        }

        $operation = $this->writeInitialized
            ? fn(): bool => rename($this->atomicTempFilePath, $this->filename)
            : fn(): bool => unlink($this->atomicTempFilePath);
        if (!$this->runSilently($operation)) {
            throw new FileAccessException("Failed to atomically replace {$this->filename}");
        }

        $this->atomicTempFilePath = null;
    }

    private function getActiveOrFinalPath(): string
    {
        if ($this->atomicWriteEnabled && $this->atomicTempFilePath !== null && is_file($this->atomicTempFilePath)) {
            return $this->atomicTempFilePath;
        }

        if ($this->localWorkingPath !== null && is_file($this->localWorkingPath)) {
            return $this->localWorkingPath;
        }

        return $this->filename;
    }

    private function initializeLockedWrite(SplFileObject $file, int $lockType): void
    {
        if ($this->writeInitialized || $lockType !== LOCK_EX) {
            return;
        }
        if (!$file->ftruncate(0) || $file->fseek(0) !== 0) {
            $this->unlock();

            throw new FileAccessException("Unable to initialize locked file {$this->filename}.");
        }
        $this->writeInitialized = true;
    }

    private function initializeRemoteWorkingPath(): void
    {
        $this->localWorkingPath = $this->createLocalTempFile('pathwise_writer_', sys_get_temp_dir());
        $this->cleanupLocalWorkingPath = true;
        $this->preloadRemoteAppendSourceIfNeeded();
    }

    /**
     * Initializes the internal state of the SafeFileWriter.
     *
     * This function is called internally whenever a file operation is requested.
     * It checks if the internal state has already been initialized, and if not,
     * initializes it. It checks if the file is writable, creating it if it does
     * not exist. Otherwise, it throws a FileAccessException.
     * @throws FileAccessException
     */
    private function initiate(string $mode = 'w'): void
    {
        if (!$this->file) {
            $targetFile = $this->resolveTargetFilePath();
            if (!$this->isRemoteTarget() && !is_writable(dirname($targetFile)) && !file_exists($targetFile)) {
                throw new FileAccessException('Cannot write to directory: ' . dirname($targetFile));
            }
            $this->file = new SplFileObject($targetFile, $mode);
            $this->writeInitialized = in_array($mode, ['w', 'a'], true);
        }
    }

    private function isRemoteTarget(): bool
    {
        return PathHelper::hasScheme($this->filename) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($this->filename));
    }

    /** @param callable(): (int|false) $write */
    private function performWrite(string $type, callable $write): int
    {
        $this->initiate($this->append ? 'a' : 'w');
        $written = $write();
        if ($written === false) {
            throw new FileAccessException("Unable to perform {$type} write for {$this->filename}.");
        }
        $this->writeInitialized = true;
        $this->trackWriteType($type);

        return $written;
    }

    private function preloadRemoteAppendSourceIfNeeded(): void
    {
        if (!$this->append || !FlysystemHelper::fileExists($this->filename) || !is_string($this->localWorkingPath)) {
            return;
        }

        try {
            FlysystemHelper::copy($this->filename, $this->localWorkingPath);
        } catch (\Throwable) {
            throw new FileAccessException("Cannot write to file: {$this->filename}");
        }
    }

    /**
     * @param callable(string): int $localDateResolver
     */
    private function resolveFileDate(callable $localDateResolver, bool $useAdapterLastModified): ?DateTime
    {
        $target = $this->getActiveOrFinalPath();
        if (is_file($target)) {
            $timestamp = $localDateResolver($target);

            return new DateTime('@' . $timestamp);
        }

        if ($useAdapterLastModified && FlysystemHelper::fileExists($this->filename)) {
            return new DateTime('@' . FlysystemHelper::lastModified($this->filename));
        }

        return null;
    }

    private function resolveNonAtomicTargetFilePath(): string
    {
        if (!$this->isRemoteTarget()) {
            return $this->filename;
        }

        if ($this->localWorkingPath === null) {
            $this->initializeRemoteWorkingPath();
        }

        return (string) $this->localWorkingPath;
    }

    private function resolveTargetFilePath(): string
    {
        if (!$this->atomicWriteEnabled) {
            return $this->resolveNonAtomicTargetFilePath();
        }

        return $this->atomicTempFilePath ??= $this->createLocalTempFile(basename($this->filename) . '.tmp_', dirname($this->filename));
    }

    private function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function syncWorkingCopyBack(): void
    {
        try {
            StreamTransferHelper::syncLocalFileToPathOrThrow(
                $this->writeInitialized,
                $this->localWorkingPath,
                $this->filename,
                fn(): \Throwable => new FileAccessException("Cannot write to file: {$this->filename}"),
            );
        } finally {
            if ($this->cleanupLocalWorkingPath && is_string($this->localWorkingPath)) {
                $this->unlinkPathSilently($this->localWorkingPath);
            }
            $this->localWorkingPath = null;
            $this->cleanupLocalWorkingPath = false;
        }
    }

    private function unlinkPathSilently(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $this->runSilently(static fn(): bool => unlink($path));
    }
}
