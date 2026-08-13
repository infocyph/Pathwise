<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Countable;
use Generator;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\MissingExtensionException;
use Infocyph\Pathwise\Utils\ReadablePathLocalizer;
use Infocyph\Pathwise\Utils\SerializedValueValidator;
use SimpleXMLElement;
use SplFileObject;
use XMLReader;

/**
 * Memory-safe file reader with explicit, statically discoverable read modes.
 */
final class SafeFileReader implements Countable
{
    private bool $cleanupLocalWorkingPath = false;

    private int $count = 0;

    private SplFileObject $file;

    private int $fileSize;

    private bool $isLocked = false;

    private ?string $localWorkingPath = null;

    private int $position = 0;

    /**
     * Initializes the SafeFileReader.
     *
     * @param string $filename The path to the file to read.
     * @param string $mode The file mode to open the file with. Defaults to 'r'.
     * @param int|null $lockType Optional LOCK_SH or LOCK_EX. Adapter-backed files are
     *                           locked only on their localized working copy.
     */
    public function __construct(
        private readonly string $filename,
        private readonly string $mode = 'r',
        private readonly ?int $lockType = null,
    ) {
        if ($lockType !== null && !in_array($lockType, [LOCK_SH, LOCK_EX], true)) {
            throw new \InvalidArgumentException('Reader lock type must be LOCK_SH, LOCK_EX, or null.');
        }
    }

    /**
     * Destructor for the SafeFileReader class.
     *
     * This method ensures that any locks held on the file are released
     * when the object is destroyed, preventing potential deadlocks
     * or access issues in subsequent file operations.
     */
    public function __destruct()
    {
        $this->releaseLock();
        if ($this->cleanupLocalWorkingPath && is_string($this->localWorkingPath) && is_file($this->localWorkingPath)) {
            $this->unlinkPathSilently($this->localWorkingPath);
        }
    }

    /** @return Generator<int, string> */
    public function characters(): Generator
    {
        $this->prepareRead();

        return $this->characterIterator();
    }

    /** @return Generator<int, string> */
    public function chunks(int $bytes = 65_536): Generator
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Chunk size must be positive.');
        }
        $this->prepareRead();

        return $this->binaryIterator($bytes);
    }

    /**
     * Returns the total number of elements read from the file.
     *
     * @return int The total number of elements read from the file.
     */
    public function count(): int
    {
        return $this->count;
    }

    /** @return Generator<int, list<string|null>> */
    public function csv(string $separator = ',', string $enclosure = '"', string $escape = '\\'): Generator
    {
        $this->prepareRead();

        return $this->csvIterator($separator, $enclosure, $escape);
    }

    /** @param list<int> $widths @return Generator<int, list<string>> */
    public function fixedWidth(array $widths): Generator
    {
        $this->prepareRead();

        return $this->fixedWidthIterator($this->validateWidths($widths));
    }

    /** @return Generator<int, mixed> */
    public function jsonArray(): Generator
    {
        $this->prepareRead();

        return $this->jsonArrayIteratorWithHandling();
    }

    /** @return Generator<int, mixed> */
    public function jsonLines(): Generator
    {
        $this->prepareRead();

        return $this->jsonIteratorWithHandling();
    }

    /** @return Generator<int, string> */
    public function lines(): Generator
    {
        $this->prepareRead();

        return $this->lineIterator();
    }

    /** @return Generator<int, string> */
    public function matchingLines(string $pattern): Generator
    {
        if ($pattern === '') {
            throw new \InvalidArgumentException('A regular-expression pattern is required.');
        }
        $this->prepareRead();

        $this->validatePattern($pattern);

        return $this->matchingLineIterator($pattern);
    }

    /** @return Generator<int, list<string>> */
    public function regexMatches(string $pattern): Generator
    {
        if ($pattern === '') {
            throw new \InvalidArgumentException('A regular-expression pattern is required.');
        }
        $this->validatePattern($pattern);
        $this->prepareRead();

        return $this->regexIterator($pattern);
    }

    /**
     * Releases the lock on the file.
     *
     * This method releases a shared lock if one has been previously acquired,
     * and marks the object as not being locked.
     */
    public function releaseLock(): void
    {
        if ($this->isLocked && isset($this->file)) {
            $this->file->flock(LOCK_UN);
            $this->isLocked = false;
        }
    }

    /** @return Generator<int, mixed> */
    public function serializedValues(): Generator
    {
        $this->prepareRead();

        return $this->serializedIterator();
    }

    /** @return Generator<int, SimpleXMLElement> */
    public function xmlElements(string $element): Generator
    {
        if (!extension_loaded('xmlreader')) {
            throw new MissingExtensionException('XML reading requires ext-xmlreader.');
        }
        if (!extension_loaded('simplexml')) {
            throw new MissingExtensionException('XML reading requires ext-simplexml.');
        }
        if ($element === '') {
            throw new \InvalidArgumentException('An XML element name is required.');
        }
        $this->prepareRead();

        return $this->xmlIterator($element);
    }

    /**
     * Applies a lock to the file.
     *
     * This method attempts to acquire a lock on the file, using an exclusive
     * lock if specified, or a shared lock otherwise. If the file is already
     * locked, it releases the current lock before attempting to apply a new one.
     *
     * @throws FileAccessException If the file cannot be locked.
     */
    private function applyLock(): void
    {
        if ($this->isLocked) {
            $this->releaseLock();
        }
        if ($this->lockType === null) {
            return;
        }
        $operation = $this->lockType === LOCK_EX ? LOCK_EX : LOCK_SH;
        if (!$this->file->flock($operation)) {
            throw new FileAccessException("Unable to lock file at path: {$this->filename}");
        }
        $this->isLocked = true;
    }

    /**
     * Reads the file in binary chunks of a specified size.
     *
     * This method reads the file in binary mode, yielding chunks of data
     * of the specified byte size until the end of the file is reached.
     * The position and count are incremented for each binary chunk read.
     *
     * @param int $bytes The number of bytes to read in each chunk. Defaults to 1024.
     * @return Generator Yields binary data chunks from the file.
     */
    private function binaryIterator(int $bytes = 65_536): Generator
    {
        while (true) {
            $chunk = $this->file->fread($bytes);
            if ($chunk === '') {
                break;
            }
            yield $chunk;
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Iterates over the file character by character.
     *
     * This function reads the file one character at a time, yielding each character.
     * The iteration is terminated when the end of the file is reached.
     * The position and count are incremented for each character read.
     *
     * @return Generator Yields each character from the file.
     */
    private function characterIterator(): Generator
    {
        while (($character = $this->file->fgetc()) !== false) {
            yield $character;
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Iterates over the file line by line, splitting each line into an array using the given CSV settings.
     *
     * The iterator is terminated when the end of the file is reached.
     *
     * The position and count are incremented each time a line is read.
     *
     * @param string $separator The character used to separate fields. Defaults to ','.
     * @param string $enclosure The character used to enclose fields. Defaults to '"'.
     * @param string $escape The character used to escape special characters. Defaults to '\\'.
     * @return Generator An iterator over the CSV lines from the file.
     */
    private function csvIterator(string $separator = ',', string $enclosure = '"', string $escape = '\\'): Generator
    {
        while (!$this->file->eof()) {
            $csvLine = $this->file->fgetcsv($separator, $enclosure, $escape);
            if ($csvLine !== false) {
                yield $csvLine;
                $this->position++;
                $this->count++;
            }
        }
    }

    private function deserializeValue(string $serializedLine): mixed
    {
        $result = unserialize($serializedLine, ['allowed_classes' => false]);
        if ($result === false && $serializedLine !== 'b:0;') {
            throw new FileAccessException('Failed to unserialize data.');
        }

        if (SerializedValueValidator::containsUnsupportedValue($result)) {
            throw new FileAccessException(
                'Serialized objects are not allowed; serialized values must use safe scalar/array types.',
            );
        }

        return $result;
    }

    /**
     * Yields an array of fields for each line of the file, where each field is of a fixed width.
     *
     * The given $widths array is used to determine the width of each field.
     * The fields are extracted from each line using substr(), and are yielded as an array.
     *
     * @param list<int> $widths An array of positive integers, each specifying the width of a field.
     * @return Generator Yields an array of fields for each line of the file.
     */
    private function fixedWidthIterator(array $widths): Generator
    {
        while (true) {
            try {
                $line = $this->file->fgets();
            } catch (\RuntimeException $exception) {
                if ($this->file->eof()) {
                    break;
                }

                throw new FileAccessException('Unable to read fixed-width input.', 0, $exception);
            }
            $fields = [];
            $offset = 0;
            foreach ($widths as $width) {
                $fields[] = substr($line, $offset, $width);
                $offset += $width;
            }
            yield $fields;
            $this->position++;
            $this->count++;
            if ($this->file->eof()) {
                break;
            }
        }
    }

    /**
     * Initializes the internal state of the SafeFileReader.
     *
     * This method is called internally whenever a file operation is requested.
     * It checks if the internal state has already been initialized, and if not,
     * initializes it. It checks if the file is readable, creates a new
     * SplFileObject instance, sets the file size and applies the lock.
     * It resets the position after that.
     *
     * @throws FileAccessException If the file is not accessible.
     */
    private function initiate(): void
    {
        if (!isset($this->file)) {
            $workingPath = $this->resolveReadablePath();
            if (!is_readable($workingPath)) {
                throw new FileAccessException("Cannot access file at path: {$this->filename}");
            }

            $this->file = new SplFileObject($workingPath, $this->mode);
            $this->fileSize = $this->file->getSize();
            $this->applyLock();
            $this->resetPosition();
        }
    }

    /**
     * Iterates over a JSON array with error handling.
     *
     * This function reads the entire content of the file, decodes it as a JSON array,
     * and yields each element. If the JSON decoding fails or the decoded value is not
     * an array, an exception is thrown.
     *
     * @return Generator Yields each element of the JSON array.
     * @throws FileAccessException If decoding the JSON array fails.
     */
    private function jsonArrayIteratorWithHandling(): Generator
    {
        $jsonContent = $this->file->fread($this->fileSize);
        if (!is_string($jsonContent)) {
            throw new FileAccessException('JSON array decoding error: failed to read file content.');
        }

        try {
            $jsonArray = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new FileAccessException('JSON array decoding error: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($jsonArray)) {
            throw new FileAccessException('JSON array decoding error: top-level value must be an array.');
        }
        foreach ($jsonArray as $element) {
            yield $element;
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Iterates over a file, decoding each line as JSON with error handling.
     *
     * This function reads the file line by line, trims each line, and attempts to decode
     * it as a JSON object. If the JSON decoding fails, an exception is thrown.
     * Successfully decoded JSON objects are yielded one by one. The position and count
     * are incremented for each valid JSON line.
     *
     * @return Generator Yields decoded JSON objects from each line of the file.
     * @throws FileAccessException If JSON decoding fails for any line.
     */
    private function jsonIteratorWithHandling(): Generator
    {
        while (!$this->file->eof()) {
            $line = trim($this->file->fgets());
            if ($line) {
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new FileAccessException('JSON decoding error: ' . $exception->getMessage(), 0, $exception);
                }
                yield $decoded;
                $this->position++;
                $this->count++;
            }
        }
    }

    /**
     * Iterates over the file line by line.
     *
     * This function reads the file one line at a time, yielding each line.
     * It continues until the end of the file is reached. The position and
     * count are incremented for each line read. If the last line is empty
     * and the end of the file is reached, the iteration is terminated.
     *
     * @return Generator Yields each line from the file.
     */
    private function lineIterator(): Generator
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            if ($this->file->eof() && trim($line) === '') {
                break;
            }
            yield $line;
            $this->position++;
            $this->count++;
        }
    }

    /** @return Generator<int, string> */
    private function matchingLineIterator(string $pattern): Generator
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            if ($this->file->eof() && $line === '') {
                break;
            }
            if (preg_match($pattern, $line) === 1) {
                yield $line;
                $this->position++;
                $this->count++;
            }
        }
    }

    private function prepareRead(): void
    {
        $this->initiate();
        $this->file->rewind();
        $this->resetPosition();
    }

    /**
     * Iterates over the file line by line, applying the given regex pattern to each line.
     *
     * For each line, if the regex pattern matches, the matches are yielded as an array.
     * The iteration is terminated when the end of the file is reached.
     *
     * The position and count are incremented each time a match is found.
     *
     * @param string $pattern The regex pattern to apply to each line.
     * @return Generator An iterator over the matches from the file.
     * @throws FileAccessException If the regex pattern is invalid.
     */
    private function regexIterator(string $pattern): Generator
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            if (preg_match($pattern, $line, $matches)) {
                yield $matches;
                $this->position++;
                $this->count++;
            }
        }
    }

    /**
     * Resets the internal position and count.
     *
     * This is used after rewinding the file to ensure the correct state is
     * maintained.
     */
    private function resetPosition(): void
    {
        $this->count = 0;
        $this->position = 0;
    }

    private function resolveReadablePath(): string
    {
        $resolved = ReadablePathLocalizer::resolve($this->filename, $this->localWorkingPath);
        $this->localWorkingPath = $resolved['path'];
        $this->cleanupLocalWorkingPath = $resolved['cleanup'];

        return $this->localWorkingPath;
    }

    /**
     * Iterates over the file as a sequence of serialized PHP values.
     *
     * Each iteration yields the deserialized value from the current line of the
     * file. The iteration is terminated when the end of the file is reached.
     *
     * @return Generator An iterator over the deserialized values from the file.
     * @throws FileAccessException If the data cannot be deserialized.
     */
    private function serializedIterator(): Generator
    {
        while (!$this->file->eof()) {
            $serializedLine = trim($this->file->fgets());
            if ($serializedLine) {
                $result = $this->deserializeValue($serializedLine);
                yield $result;
                $this->position++;
                $this->count++;
            }
        }
    }

    private function unlinkPathSilently(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        set_error_handler(static fn(): bool => true);

        try {
            unlink($path);
        } finally {
            restore_error_handler();
        }
    }

    private function validatePattern(string $pattern): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $valid = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
        if (!$valid) {
            throw new \InvalidArgumentException('Invalid regular-expression pattern.');
        }
    }

    /**
     * @param list<int> $widths
     * @return list<int>
     */
    private function validateWidths(array $widths): array
    {
        if ($widths === []) {
            throw new \InvalidArgumentException('At least one fixed-width field definition is required.');
        }
        foreach ($widths as $width) {
            if ($width < 1) {
                throw new \InvalidArgumentException('Fixed-width definitions must be positive integers.');
            }
        }

        return $widths;
    }

    /**
     * Reads an XML file and yields each element with the given name.
     *
     * The iterator yields a SimpleXMLElement for each element with the given name.
     * The elements are yielded in the order they appear in the file.
     *
     * Note that this iterator does not support seeking or rewinding.
     *
     * @param string $element The name of the element to yield.
     * @return Generator Yields each element with the given name.
     * @throws FileAccessException If the file cannot be opened or read.
     */
    private function xmlIterator(string $element): Generator
    {
        $reader = new XMLReader();
        if (!$reader->open($this->localWorkingPath ?? $this->filename)) {
            throw new FileAccessException("Failed to open XML file: {$this->filename}");
        }

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $element) {
                yield new SimpleXMLElement($reader->readOuterXml());
            }
        }
        $reader->close();
    }
}
