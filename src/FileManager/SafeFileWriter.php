<?php

namespace Infocyph\Pathwise\FileManager;

use Countable;
use DateTime;
use DateTimeInterface;
use Exception;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use JsonSerializable;
use SimpleXMLElement;
use SplFileObject;
use Stringable;

/**
 * @method SafeFileReader character() Character iterator
 * @method SafeFileReader line() Line iterator
 * @method SafeFileReader csv(string $separator = ",", string $enclosure = "\"", string $escape = "\\") CSV iterator
 * @method SafeFileReader binary(int $bytes = 1024) Binary iterator
 * @method SafeFileReader json() JSON line-by-line iterator
 * @method SafeFileReader regex(string $pattern) Regex iterator
 * @method SafeFileReader fixedWidth(array $widths) Fixed-width field iterator
 * @method SafeFileReader xml(string $element) XML iterator
 * @method SafeFileReader serialized() Serialized object iterator
 * @method SafeFileReader jsonArray() JSON array iterator
 */
class SafeFileWriter implements Countable, Stringable, JsonSerializable
{
    private ?string $atomicTempFilePath = null;
    private bool $atomicWriteEnabled = false;
    private bool $cleanupLocalWorkingPath = false;
    private ?SplFileObject $file = null;
    private bool $isLocked = false;
    private ?string $localWorkingPath = null;
    private bool $syncBackOnClose = false;
    private int $writeCount = 0;
    private array $writeTypesCount = [];

    /**
     * Creates a new SafeFileWriter instance.
     *
     * @param string $filename The name of the file to write to.
     * @param bool $append Whether to append to the existing file or truncate it.
     */
    public function __construct(private readonly string $filename, private readonly bool $append = false)
    {
    }

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
                @unlink($this->localWorkingPath);
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
     * @param array $params The parameters to be passed to the specific write operation.
     * @throws Exception If the specified write type is unknown.
     */
    public function __call(string $type, array $params)
    {
        $this->initiate($this->append ? 'a' : 'w');
        $returnable = match ($type) {
            'character' => $this->writeCharacter(...$params),
            'line' => $this->writeLine(...$params),
            'csv' => $this->writeCSV(...$params),
            'binary' => $this->writeBinary(...$params),
            'json' => $this->writeJSON(...$params),
            'regex' => $this->writePatternMatch(...$params),
            'fixedWidth' => $this->writeFixedWidth(...$params),
            'xml' => $this->writeXML(...$params),
            'serialized' => $this->writeSerialized(...$params),
            'jsonArray' => $this->writeJSONArray(...$params),
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
            "SafeFileWriter [File: %s, Size: %d bytes, Writes: %d]",
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
     * @return array The associative array to be JSON serialized.
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
        $this->initiate($this->append ? 'a' : 'w');
        $attempt = 0;

        do {
            $lockMode = $waitForLock ? $lockType : $lockType | LOCK_NB;
            if ($this->file->flock($lockMode)) {
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
            if (!@rename($this->atomicTempFilePath, $this->localWorkingPath)) {
                if (!@copy($this->atomicTempFilePath, $this->localWorkingPath)) {
                    throw new FileAccessException("Failed to finalize atomic write for {$this->filename}");
                }
                @unlink($this->atomicTempFilePath);
            }
            $this->syncBackOnClose = true;
            $this->atomicTempFilePath = null;

            return;
        }

        if (!@rename($this->atomicTempFilePath, $this->filename)) {
            if (!@copy($this->atomicTempFilePath, $this->filename)) {
                throw new FileAccessException("Failed to finalize atomic write for {$this->filename}");
            }
            @unlink($this->atomicTempFilePath);
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
                throw new FileAccessException("Cannot write to directory: " . dirname($targetFile));
            }
            $this->file = new SplFileObject($targetFile, $mode);
        }
    }

    private function isRemoteTarget(): bool
    {
        return PathHelper::hasScheme($this->filename) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($this->filename));
    }

    private function resolveTargetFilePath(): string
    {
        if (!$this->atomicWriteEnabled) {
            if ($this->isRemoteTarget()) {
                if ($this->localWorkingPath !== null) {
                    return $this->localWorkingPath;
                }

                $this->localWorkingPath = $this->createLocalTempFile('pathwise_writer_');
                $this->cleanupLocalWorkingPath = true;
                $this->syncBackOnClose = true;

                if ($this->append && FlysystemHelper::fileExists($this->filename)) {
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

                return $this->localWorkingPath;
            }

            return $this->filename;
        }

        if ($this->atomicTempFilePath !== null) {
            return $this->atomicTempFilePath;
        }

        if ($this->isRemoteTarget()) {
            $tempFile = $this->createLocalTempFile('pathwise_writer_atomic_');
        } else {
            $directory = dirname($this->filename);
            $prefix = basename($this->filename) . '.tmp_';
            $tempFile = tempnam($directory, $prefix);
            if ($tempFile === false) {
                throw new FileAccessException("Unable to create temporary file for atomic write: {$this->filename}");
            }
        }

        $this->atomicTempFilePath = PathHelper::normalize($tempFile);

        return $this->atomicTempFilePath;
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

    /**
     * Tracks the number of times a write type is called.
     *
     * @param string $type The type of write (e.g. 'character', 'line', 'csv', etc.).
     */
    private function trackWriteType(string $type): void
    {
        $type = strtolower($type);
        if (!isset($this->writeTypesCount[$type])) {
            $this->writeTypesCount[$type] = 0;
        }
        $this->writeTypesCount[$type]++;
    }

    /**
     * Writes a string of binary data to the file.
     *
     * This function takes a string of binary data and writes it to the file.
     * The write count is incremented after writing the data.
     *
     * @param string $data The binary data to write.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writeBinary(string $data): int|false
    {
        $this->writeCount++;
        return $this->file->fwrite($data);
    }

    /**
     * Writes a single character to the file.
     *
     * This function takes a single character and writes it to the file.
     * The write count is incremented after writing the data.
     *
     * @param string $char The character to write to the file.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writeCharacter(string $char): int|false
    {
        $this->writeCount++;
        return $this->file->fwrite($char);
    }

    /**
     * Writes a row of data to the file in CSV format.
     *
     * This function takes an array of data and writes it to the file
     * as a CSV line using the specified separator, enclosure, and
     * escape characters. It increments the write count after writing.
     *
     * @param array $row The data to write as a CSV line.
     * @param string $separator The character used to separate fields. Defaults to ','.
     * @param string $enclosure The character used to enclose fields. Defaults to '"'.
     * @param string $escape The character used to escape special characters. Defaults to '\\'.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writeCSV(
        array $row,
        string $separator = ",",
        string $enclosure = "\"",
        string $escape = "\\",
    ): int|false {
        $this->writeCount++;
        return $this->file->fputcsv($row, $separator, $enclosure, $escape);
    }

    /**
     * Writes a line of fixed-width fields to the file.
     *
     * The given $data array is padded and written to the file, with each
     * element padded to the corresponding width in the $widths array.
     *
     * @param array $data The data to write. Each element is written as a string.
     * @param array $widths The widths of each field. Each element is a positive integer.
     * @return int|false The number of bytes written, or false on failure.
     * @throws Exception If the count of $data does not match the count of $widths.
     */
    private function writeFixedWidth(array $data, array $widths): int|false
    {
        if (count($data) !== count($widths)) {
            throw new Exception("Data and widths arrays must match.");
        }
        $line = '';
        foreach ($data as $index => $field) {
            $line .= str_pad((string) $field, $widths[$index]);
        }
        $this->writeCount++;
        return $this->file->fwrite($line . PHP_EOL);
    }

    /**
     * Writes JSON data to the file.
     *
     * This function encodes the provided data as JSON and writes it to the file.
     * Optionally, it can format the JSON with indentation and whitespace for readability.
     *
     * @param mixed $data The data to encode as JSON and write.
     * @param bool $prettyPrint If true, the JSON will be formatted for readability. Defaults to false.
     * @return int|false The number of bytes written, or false on failure.
     * @throws Exception If JSON encoding fails.
     */
    private function writeJSON(mixed $data, bool $prettyPrint = false): int|false
    {
        $jsonOptions = $prettyPrint ? JSON_PRETTY_PRINT : 0;
        $jsonData = json_encode($data, $jsonOptions);
        if ($jsonData === false) {
            throw new Exception("JSON encoding failed: " . json_last_error_msg());
        }
        $this->writeCount++;
        return $this->file->fwrite($jsonData . PHP_EOL);
    }

    /**
     * Writes a JSON array to the file.
     *
     * @param array $data The array of data to write.
     * @param bool $prettyPrint If true, the JSON will be formatted with
     *     indentation and whitespace for readability. Defaults to false.
     * @return int|false The number of bytes written, or false on failure.
     * @throws Exception If the JSON encoding fails.
     */
    private function writeJSONArray(array $data, bool $prettyPrint = false): int|false
    {
        $jsonOptions = $prettyPrint ? JSON_PRETTY_PRINT : 0;
        $jsonData = json_encode($data, $jsonOptions);
        if ($jsonData === false) {
            throw new Exception("JSON encoding failed: " . json_last_error_msg());
        }
        $this->writeCount++;
        return $this->file->fwrite($jsonData . PHP_EOL);
    }

    /**
     * Writes a line of text to the file.
     *
     * This function takes a string of content and writes it to the file,
     * appending a newline character at the end.
     * The write count is incremented after writing the data.
     *
     * @param string $content The content to write to the file.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writeLine(string $content): int|false
    {
        $this->writeCount++;
        return $this->file->fwrite($content . PHP_EOL);
    }

    /**
     * Writes the given content to the file if it matches the specified pattern.
     *
     * This function checks if the provided content matches the given regex pattern.
     * If a match is found, the content is written to the file with a newline appended.
     * The write count is incremented each time content is successfully written.
     *
     * @param string $content The content to be checked and potentially written.
     * @param string $pattern The regex pattern to match against the content.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writePatternMatch(string $content, string $pattern): int|false
    {
        if (preg_match($pattern, $content)) {
            $this->writeCount++;
            return $this->file->fwrite($content . PHP_EOL);
        }
        return false;
    }

    /**
     * Writes a serialized representation of the given data to the file.
     *
     * The `serialize` function is used to convert the data into a string
     * representation. The resulting string is then written to the file,
     * followed by a newline.
     *
     * @param mixed $data The data to serialize and write.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writeSerialized(mixed $data): int|false
    {
        $serializedData = serialize($data);
        $this->writeCount++;
        return $this->file->fwrite($serializedData . PHP_EOL);
    }

    /**
     * Writes an XML element to the file.
     *
     * This function takes a SimpleXMLElement, converts it to an XML string,
     * and writes it to the file, appending a newline character.
     *
     * @param SimpleXMLElement $element The XML element to write.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writeXML(SimpleXMLElement $element): int|false
    {
        $this->writeCount++;
        return $this->file->fwrite($element->asXML() . PHP_EOL);
    }
}
