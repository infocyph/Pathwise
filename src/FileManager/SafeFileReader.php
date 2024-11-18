<?php

namespace Infocyph\Pathwise\FileManager;

use Countable;
use Exception;
use Generator;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Iterator;
use NoRewindIterator;
use SeekableIterator;
use SimpleXMLElement;
use SplFileObject;
use XMLReader;

/**
 * Memory-safe file reader with multiple read modes, locking, and interface support.
 *
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
final class SafeFileReader implements Countable, Iterator, SeekableIterator
{
    private SplFileObject $file;
    private int $count = 0;
    private int $position = 0;
    private int $fileSize;
    private ?Generator $currentIterator = null;
    private bool $isLocked = false;

    /**
     * Initializes the SafeFileReader.
     *
     * @param string $filename The path to the file to read.
     * @param string $mode The file mode to open the file with. Defaults to 'r'.
     * @param bool $exclusiveLock When true, a lock is acquired on the file before
     *     reading. The type of lock is determined by the $mode parameter.
     */
    public function __construct(
        private readonly string $filename,
        private readonly string $mode = 'r',
        private readonly bool $exclusiveLock = false,
    ) {
    }

    /**
     * Dynamically invokes an iterator based on the specified type.
     *
     * This method initializes the file reader and returns an iterator
     * for the requested type. Supported types include 'character', 'line',
     * 'csv', 'binary', 'json', 'regex', 'fixedWidth', 'xml', 'serialized',
     * and 'jsonArray'. Each type corresponds to a specific method that
     * generates the appropriate iterator for processing the file content.
     *
     * @param string $type The type of iterator to create.
     * @param array $params Parameters to pass to the iterator method.
     * @return NoRewindIterator The requested iterator wrapped in a NoRewindIterator.
     * @throws Exception If the specified iterator type is unknown.
     */
    public function __call(string $type, array $params): NoRewindIterator
    {
        $this->initiate();
        $this->currentIterator = match ($type) {
            'character' => $this->characterIterator(),
            'line' => $this->lineIterator(),
            'csv' => $this->csvIterator(...$params),
            'binary' => $this->binaryIterator(...$params),
            'json' => $this->jsonIteratorWithHandling(),
            'regex' => $this->regexIterator(...$params),
            'fixedWidth' => $this->fixedWidthIterator(...$params),
            'xml' => $this->xmlIterator(...$params),
            'serialized' => $this->serializedIterator(),
            'jsonArray' => $this->jsonArrayIteratorWithHandling(),
            default => throw new Exception("Unknown iterator type '$type'"),
        };

        return new NoRewindIterator($this->currentIterator);
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
            if (!is_readable($this->filename)) {
                throw new FileAccessException("Cannot access file at path: {$this->filename}");
            }
            $this->file = new SplFileObject($this->filename, $this->mode);
            $this->fileSize = $this->file->getSize();
            $this->applyLock();
            $this->resetPosition();
        }
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
        $lockType = $this->exclusiveLock ? LOCK_EX : LOCK_SH;
        if (!$this->file->flock($lockType)) {
            throw new FileAccessException("Unable to lock file at path: {$this->filename}");
        }
        $this->isLocked = true;
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
    }

    /**
     * Returns the current element in the file.
     *
     * This method returns the current element from the internal iterator.
     * The type of the element depends on the iterator type, which is determined
     * by the method call that created the iterator.
     *
     * @return mixed The current element in the file.
     */
    public function current(): mixed
    {
        return $this->currentIterator?->current();
    }

    /**
     * Returns the current position in the file.
     *
     * This method returns the current value of the internal position counter,
     * which is incremented by the `next` method and reset by the `rewind`
     * method.
     *
     * @return int The current position in the file.
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Moves the internal iterator to the next position.
     *
     * This method calls `next` on the current iterator, and then increments the
     * internal position counter. It should be called after `valid` has been
     * called to verify that the iterator is valid.
     */
    public function next(): void
    {
        $this->currentIterator?->next();
        $this->position++;
    }

    /**
     * Resets the file pointer to the beginning of the file.
     *
     * This method rewinds the internal file pointer to the beginning of the file,
     * resets the internal position counter, and rewinds the current iterator
     * instance if one exists.
     */
    public function rewind(): void
    {
        $this->file->rewind();
        $this->resetPosition();
        $this->currentIterator?->rewind();
    }

    /**
     * Checks if the current element is valid.
     *
     * This method returns the validity of the current element from the internal
     * iterator. If the iterator is not initialized, it returns false.
     *
     * @return bool True if the current element is valid, false otherwise.
     */
    public function valid(): bool
    {
        return $this->currentIterator?->valid() ?? false;
    }

    /**
     * Seeks to the specified position in the file.
     *
     * This method initializes the file if necessary and then moves the internal pointer
     * to the specified offset. If the offset is negative, an exception is thrown.
     *
     * @param int $offset The position to seek to in the file.
     * @throws Exception If the specified offset is negative.
     */
    public function seek(int $offset): void
    {
        $this->initiate();
        if ($offset < 0) {
            throw new Exception("Invalid position ($offset)");
        }
        $this->file->seek($offset);
        $this->position = $offset;
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

    /**
     * Resets the internal state of the file reader.
     *
     * This function is used to reset the internal state of the file reader to
     * its initial state. It rewinds the file to its beginning and resets the
     * count and position.
     */
    private function reset(): void
    {
        $this->file->rewind();
        $this->resetPosition();
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
     * @throws Exception If the file cannot be opened or read.
     */
    private function xmlIterator(string $element): Generator
    {
        $reader = new XMLReader();
        if (!$reader->open($this->filename)) {
            throw new Exception("Failed to open XML file: {$this->filename}");
        }

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $element) {
                yield new SimpleXMLElement($reader->readOuterXml());
            }
        }
        $reader->close();
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
        while (!$this->file->eof()) {
            yield $this->file->fgetc();
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
    private function binaryIterator(int $bytes = 1024): Generator
    {
        while (!$this->file->eof()) {
            yield $this->file->fread($bytes);
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
     * @throws Exception If JSON decoding fails for any line.
     */
    private function jsonIteratorWithHandling(): Generator
    {
        while (!$this->file->eof()) {
            $line = trim($this->file->fgets());
            if ($line) {
                $decoded = json_decode($line, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('JSON decoding error: ' . json_last_error_msg());
                }
                yield $decoded;
                $this->position++;
                $this->count++;
            }
        }
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
     * @throws Exception If the regex pattern is invalid.
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
     * Yields an array of fields for each line of the file, where each field is of a fixed width.
     *
     * The given $widths array is used to determine the width of each field.
     * The fields are extracted from each line using substr(), and are yielded as an array.
     *
     * @param array $widths An array of positive integers, each specifying the width of a field.
     * @return Generator Yields an array of fields for each line of the file.
     */
    private function fixedWidthIterator(array $widths): Generator
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            $fields = [];
            $offset = 0;
            foreach ($widths as $width) {
                $fields[] = substr($line, $offset, $width);
                $offset += $width;
            }
            yield $fields;
            $this->position++;
            $this->count++;
        }
    }

    /**
     * Iterates over the file as a sequence of serialized PHP values.
     *
     * Each iteration yields the deserialized value from the current line of the
     * file. The iteration is terminated when the end of the file is reached.
     *
     * @return Generator An iterator over the deserialized values from the file.
     * @throws Exception If the data cannot be deserialized.
     */
    private function serializedIterator(): Generator
    {
        while (!$this->file->eof()) {
            $serializedLine = trim($this->file->fgets());
            if ($serializedLine) {
                $result = unserialize($serializedLine);
                if ($result === false && $serializedLine !== 'b:0;') {
                    throw new Exception('Failed to unserialize data.');
                }
                yield $result;
                $this->position++;
                $this->count++;
            }
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
     * @throws Exception If decoding the JSON array fails.
     */
    private function jsonArrayIteratorWithHandling(): Generator
    {
        $jsonArray = json_decode($this->file->fread($this->fileSize), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonArray)) {
            throw new Exception('JSON array decoding error: ' . json_last_error_msg());
        }
        foreach ($jsonArray as $element) {
            yield $element;
            $this->position++;
            $this->count++;
        }
    }
}
