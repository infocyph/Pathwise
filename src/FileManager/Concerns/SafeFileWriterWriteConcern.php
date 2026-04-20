<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager\Concerns;

use Exception;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use SimpleXMLElement;
use SplFileObject;

trait SafeFileWriterWriteConcern
{
    /**
     * @param list<mixed> $params
     */
    private function optionalBoolParam(array $params, int $index, bool $default): bool
    {
        $value = $params[$index] ?? null;
        if ($value === null) {
            return $default;
        }

        if (!is_bool($value)) {
            throw new Exception("Expected bool parameter at index {$index}.");
        }

        return $value;
    }

    /**
     * @param list<mixed> $params
     */
    private function optionalStringParam(array $params, int $index, string $default): string
    {
        $value = $params[$index] ?? null;
        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            throw new Exception("Expected string parameter at index {$index}.");
        }

        return $value;
    }

    /**
     * @param list<mixed> $params
     * @return array<int|string, mixed>
     */
    private function requireArrayParam(array $params, int $index, string $type): array
    {
        $value = $params[$index] ?? null;
        if (!is_array($value)) {
            throw new Exception("Write type '{$type}' expects array parameter at index {$index}.");
        }

        return $value;
    }

    /**
     * @param list<mixed> $params
     * @return array<int, string|int|float|bool|null>
     */
    private function requireCsvRowParam(array $params, int $index, string $type): array
    {
        $value = $this->requireArrayParam($params, $index, $type);
        $row = [];
        foreach ($value as $column) {
            if (!is_string($column) && !is_int($column) && !is_float($column) && !is_bool($column) && $column !== null) {
                throw new Exception("Write type '{$type}' expects scalar CSV values.");
            }

            $row[] = $column;
        }

        return $row;
    }

    private function requireFileHandle(): SplFileObject
    {
        if (!$this->file instanceof SplFileObject) {
            throw new FileAccessException("Cannot write to file: {$this->filename}");
        }

        return $this->file;
    }

    /**
     * @param list<mixed> $params
     * @return array<int, string|int|float|bool|null>
     */
    private function requireFixedWidthDataParam(array $params, int $index, string $type): array
    {
        return $this->requireCsvRowParam($params, $index, $type);
    }

    /**
     * @param list<mixed> $params
     */
    private function requireStringParam(array $params, int $index, string $type): string
    {
        $value = $params[$index] ?? null;
        if (!is_string($value)) {
            throw new Exception("Write type '{$type}' expects string parameter at index {$index}.");
        }

        return $value;
    }

    /**
     * @param list<mixed> $params
     * @return array<int, int>
     */
    private function requireWidthsParam(array $params, int $index, string $type): array
    {
        $value = $this->requireArrayParam($params, $index, $type);
        $widths = [];
        foreach ($value as $width) {
            if (!is_int($width)) {
                throw new Exception("Write type '{$type}' expects integer widths.");
            }

            $widths[] = $width;
        }

        return $widths;
    }

    /**
     * @param list<mixed> $params
     */
    private function requireXmlParam(array $params, int $index, string $type): SimpleXMLElement
    {
        $value = $params[$index] ?? null;
        if (!$value instanceof SimpleXMLElement) {
            throw new Exception("Write type '{$type}' expects SimpleXMLElement at index {$index}.");
        }

        return $value;
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

        return $this->requireFileHandle()->fwrite($data);
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

        return $this->requireFileHandle()->fwrite($char);
    }

    /**
     * Writes a row of data to the file in CSV format.
     *
     * This function takes an array of data and writes it to the file
     * as a CSV line using the specified separator, enclosure, and
     * escape characters. It increments the write count after writing.
     *
     * @param array<int, string|int|float|bool|null> $row The data to write as a CSV line.
     * @param string $separator The character used to separate fields. Defaults to ','.
     * @param string $enclosure The character used to enclose fields. Defaults to '"'.
     * @param string $escape The character used to escape special characters. Defaults to '\\'.
     * @return int|false The number of bytes written, or false on failure.
     */
    private function writeCSV(
        array $row,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ): int|false {
        $this->writeCount++;

        return $this->requireFileHandle()->fputcsv($row, $separator, $enclosure, $escape);
    }

    /**
     * Writes a line of fixed-width fields to the file.
     *
     * The given $data array is padded and written to the file, with each
     * element padded to the corresponding width in the $widths array.
     *
     * @param array<int, string|int|float|bool|null> $data The data to write. Each element is written as a string.
     * @param array<int, int> $widths The widths of each field. Each element is a positive integer.
     * @return int|false The number of bytes written, or false on failure.
     * @throws Exception If the count of $data does not match the count of $widths.
     */
    private function writeFixedWidth(array $data, array $widths): int|false
    {
        if (count($data) !== count($widths)) {
            throw new Exception('Data and widths arrays must match.');
        }
        $line = '';
        foreach ($data as $index => $field) {
            $width = $widths[$index] ?? null;
            if (!is_int($width)) {
                throw new Exception('Widths must contain integers.');
            }

            $line .= str_pad((string) $field, $width);
        }
        $this->writeCount++;

        return $this->requireFileHandle()->fwrite($line . PHP_EOL);
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
            throw new Exception('JSON encoding failed: ' . json_last_error_msg());
        }
        $this->writeCount++;

        return $this->requireFileHandle()->fwrite($jsonData . PHP_EOL);
    }

    /**
     * Writes a JSON array to the file.
     *
     * @param array<int|string, mixed> $data The array of data to write.
     * @param bool $prettyPrint If true, the JSON will be formatted with
     *                          indentation and whitespace for readability. Defaults to false.
     * @return int|false The number of bytes written, or false on failure.
     * @throws Exception If the JSON encoding fails.
     */
    private function writeJSONArray(array $data, bool $prettyPrint = false): int|false
    {
        $jsonOptions = $prettyPrint ? JSON_PRETTY_PRINT : 0;
        $jsonData = json_encode($data, $jsonOptions);
        if ($jsonData === false) {
            throw new Exception('JSON encoding failed: ' . json_last_error_msg());
        }
        $this->writeCount++;

        return $this->requireFileHandle()->fwrite($jsonData . PHP_EOL);
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

        return $this->requireFileHandle()->fwrite($content . PHP_EOL);
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

            return $this->requireFileHandle()->fwrite($content . PHP_EOL);
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

        return $this->requireFileHandle()->fwrite($serializedData . PHP_EOL);
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

        return $this->requireFileHandle()->fwrite($element->asXML() . PHP_EOL);
    }
}
