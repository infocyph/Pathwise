<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Countable;
use DateTime;
use DateTimeInterface;
use Exception;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\FileManager\Concerns\SafeFileWriterWriteConcern;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use JsonSerializable;
use SplFileObject;
use Stringable;

/**
 * @method SafeFileReader character() Character iterator
 * @method SafeFileReader line() Line iterator
 * @method SafeFileReader csv(string $separator = ",", string $enclosure = "\"", string $escape = "\\") CSV iterator
 * @method SafeFileReader binary(int $bytes = 1024) Binary iterator
 * @method SafeFileReader json() JSON line-by-line iterator
 * @method SafeFileReader regex(string $pattern) Regex iterator
 * @method SafeFileReader fixedWidth(array<int, int> $widths) Fixed-width field iterator
 * @method SafeFileReader xml(string $element) XML iterator
 * @method SafeFileReader serialized() Serialized object iterator
 * @method SafeFileReader jsonArray() JSON array iterator
 */
class SafeFileWriter implements Countable, Stringable, JsonSerializable
{
    use SafeFileWriterWriteConcern;

    private ?string $atomicTempFilePath = null;

    private bool $atomicWriteEnabled = false;

    private bool $cleanupLocalWorkingPath = false;

    private ?SplFileObject $file = null;

    private bool $isLocked = false;

    private ?string $localWorkingPath = null;

    private bool $syncBackOnClose = false;

    private int $writeCount = 0;

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
            if ($this->cleanupLocalWorkingPath && is_string($this->localWorkingPath) && is_file($this->localWorkingPath)) {
                $this->unlinkPathSilently($this->localWorkingPath);
            }
        }
    }

    /**
     * Dynamically handles different write operations based on the specified type.
     *
     * This method uses a dynamic approach to invoke various write operations such as
     * 'character', 'line', 'csv', 'binary', 'json', 'regex', 'fixedWidth', 'xml',
     * 'serialized', and 'jsonArray'. It initializes the file for writing, acquires
     * a lock, performs the specified write operation, tracks the write type, and
     * finally releases the lock.
     *
     * @param string $type The type of write operation to perform.
     * @param list<mixed> $params The parameters to be passed to the specific write operation.
     * @throws Exception If the specified write type is unknown.
     */
    public function __call(string $type, array $params): mixed
    {
        $this->initiate($this->append ? 'a' : 'w');
        $returnable = match ($type) {
            'character' => $this->writeCharacter($this->requireStringParam($params, 0, $type)),
            'line' => $this->writeLine($this->requireStringParam($params, 0, $type)),
            'csv' => $this->writeCSV(
                $this->requireCsvRowParam($params, 0, $type),
                $this->optionalStringParam($params, 1, ','),
                $this->optionalStringParam($params, 2, '"'),
                $this->optionalStringParam($params, 3, '\\'),
            ),
            'binary' => $this->writeBinary($this->requireStringParam($params, 0, $type)),
            'json' => $this->writeJSON($params[0] ?? null, $this->optionalBoolParam($params, 1, false)),
            'regex' => $this->writePatternMatch(
                $this->requireStringParam($params, 0, $type),
                $this->requireStringParam($params, 1, $type),
            ),
            'fixedWidth' => $this->writeFixedWidth(
                $this->requireFixedWidthDataParam($params, 0, $type),
                $this->requireWidthsParam($params, 1, $type),
            ),
            'xml' => $this->writeXML($this->requireXmlParam($params, 0, $type)),
            'serialized' => $this->writeSerialized($params[0] ?? null),
            'jsonArray' => $this->writeJSONArray(
                $this->requireArrayParam($params, 0, $type),
                $this->optionalBoolParam($params, 1, false),
            ),
            default => throw new Exception("Unknown write type '$type'"),
        };
        $this->trackWriteType($type);

        return $returnable;
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
     * Enable or disable atomic write mode.
     *
     * @param bool $enabled If true, enable atomic writes.
     * @return self This instance for method chaining.
     * @throws FileAccessException If atomic mode is enabled in append mode.
     */
    public function enableAtomicWrite(bool $enabled = true): self
    {
        if ($enabled && $this->append) {
            throw new FileAccessException('Atomic write mode is not supported in append mode.');
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
    public function getCreationDate(): DateTime
    {
        $target = $this->getActiveOrFinalPath();
        if (is_file($target)) {
            return new DateTime('@' . filectime($target));
        }

        if (FlysystemHelper::fileExists($this->filename)) {
            return new DateTime('@' . FlysystemHelper::lastModified($this->filename));
        }

        return new DateTime();
    }

    /**
     * Gets the last modification date of the file.
     *
     * @return DateTime The last modification date of the file.
     */
    public function getModificationDate(): DateTime
    {
        $target = $this->getActiveOrFinalPath();
        if (is_file($target)) {
            return new DateTime('@' . filemtime($target));
        }

        if (FlysystemHelper::fileExists($this->filename)) {
            return new DateTime('@' . FlysystemHelper::lastModified($this->filename));
        }

        return new DateTime();
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
            'modificationDate' => $this->getModificationDate()->format(DateTimeInterface::ATOM),
            'creationDate' => $this->getCreationDate()->format(DateTimeInterface::ATOM),
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
        if (!in_array($lockType, [LOCK_EX, LOCK_SH], true)) {
            throw new FileAccessException("Invalid lock type for file {$this->filename}.");
        }

        $this->initiate($this->append ? 'a' : 'w');
        $file = $this->requireFileHandle();
        $attempt = 0;

        do {
            $lockMode = $waitForLock ? $lockType : $lockType | LOCK_NB;
            if ($file->flock($lockMode)) {
                $this->isLocked = true;

                return;
            }
            if (!$waitForLock) {
                break;
            }
            usleep($delay * 1000);
            $attempt++;
        } while ($attempt < $retries);

        throw new FileAccessException("Failed to acquire lock on file {$this->filename} after $retries attempts.");
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
    }

    /**
     * Releases the lock on the file.
     *
     * @throws FileAccessException If unlock fails.
     */
    public function unlock(): void
    {
        if ($this->isLocked && $this->file && !$this->file->flock(LOCK_UN)) {
            throw new FileAccessException("Failed to release lock on file {$this->filename}.");
        }
        $this->isLocked = false;
    }

    /**
     * Verify the file's checksum against an expected value.
     *
     * @param string $expectedChecksum The expected checksum.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return bool True if the checksum matches, false otherwise.
     * @throws Exception If the algorithm is not supported.
     */
    public function verifyChecksum(string $expectedChecksum, string $algorithm = 'sha256'): bool
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new Exception("Unsupported checksum algorithm: {$algorithm}");
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
     * @throws Exception
     */
    public function writeAndVerify(string $content, string $algorithm = 'sha256'): bool
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new Exception("Unsupported checksum algorithm: {$algorithm}");
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
        if (!is_string($fileHash)) {
            return false;
        }

        return hash_equals(hash($algorithm, $content), $fileHash);
    }

    private function createAtomicTempFilePath(): string
    {
        if ($this->isRemoteTarget()) {
            return $this->createLocalTempFile('pathwise_writer_atomic_');
        }

        $directory = dirname($this->filename);
        $prefix = basename($this->filename) . '.tmp_';
        $tempFile = tempnam($directory, $prefix);
        if ($tempFile === false) {
            throw new FileAccessException("Unable to create temporary file for atomic write: {$this->filename}");
        }

        return $tempFile;
    }

    private function createLocalTempFile(string $prefix): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix);
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

        if ($this->isRemoteTarget()) {
            $this->localWorkingPath ??= $this->createLocalTempFile('pathwise_writer_sync_');
            if (!$this->runSilently(fn(): bool => rename($this->atomicTempFilePath, $this->localWorkingPath))) {
                if (!$this->runSilently(fn(): bool => copy($this->atomicTempFilePath, $this->localWorkingPath))) {
                    throw new FileAccessException("Failed to finalize atomic write for {$this->filename}");
                }
                $this->unlinkPathSilently($this->atomicTempFilePath);
            }
            $this->syncBackOnClose = true;
            $this->atomicTempFilePath = null;

            return;
        }

        if (!$this->runSilently(fn(): bool => rename($this->atomicTempFilePath, $this->filename))) {
            if (!$this->runSilently(fn(): bool => copy($this->atomicTempFilePath, $this->filename))) {
                throw new FileAccessException("Failed to finalize atomic write for {$this->filename}");
            }
            $this->unlinkPathSilently($this->atomicTempFilePath);
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

    private function initializeRemoteWorkingPath(): void
    {
        $this->localWorkingPath = $this->createLocalTempFile('pathwise_writer_');
        $this->cleanupLocalWorkingPath = true;
        $this->syncBackOnClose = true;
        $this->preloadRemoteAppendSourceIfNeeded();
    }

    /**
     * Initializes the internal state of the SafeFileWriter.
     *
     * This function is called internally whenever a write operation is requested.
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
        }
    }

    private function isRemoteTarget(): bool
    {
        return PathHelper::hasScheme($this->filename) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($this->filename));
    }

    private function preloadRemoteAppendSourceIfNeeded(): void
    {
        if (!$this->append || !FlysystemHelper::fileExists($this->filename) || !is_string($this->localWorkingPath)) {
            return;
        }

        $source = FlysystemHelper::readStream($this->filename);
        $target = fopen($this->localWorkingPath, 'wb');
        if (!is_resource($source) || !is_resource($target)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }

            throw new FileAccessException("Cannot write to file: {$this->filename}");
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
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

        if ($this->atomicTempFilePath !== null) {
            return $this->atomicTempFilePath;
        }

        $this->atomicTempFilePath = PathHelper::normalize($this->createAtomicTempFilePath());

        return $this->atomicTempFilePath;
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
        if (!$this->syncBackOnClose || !is_string($this->localWorkingPath) || !is_file($this->localWorkingPath)) {
            return;
        }

        $stream = fopen($this->localWorkingPath, 'rb');
        if (!is_resource($stream)) {
            throw new FileAccessException("Cannot write to file: {$this->filename}");
        }

        try {
            FlysystemHelper::writeStream($this->filename, $stream);
        } finally {
            fclose($stream);
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
