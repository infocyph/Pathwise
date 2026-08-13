<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager\Concerns;

use Infocyph\Pathwise\Exceptions\FileAccessException;
use SimpleXMLElement;
use SplFileObject;

trait SafeFileWriterWriteConcern
{
    private function isSafeSerializedValue(mixed $value, int $depth = 0): bool
    {
        if ($depth > 256) {
            return false;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        return array_all($value, fn(mixed $item): bool => $this->isSafeSerializedValue($item, $depth + 1));
    }

    /**
     * @param list<mixed> $params
     */
    private function optionalBoolParam(array $params, int $index, bool $default): bool
    {
        $value = $this->optionalParamValue($params, $index, $default);
        if (!is_bool($value)) {
            throw new FileAccessException("Expected bool parameter at index {$index}.");
        }

        return $value;
    }

    /**
     * @param list<mixed> $params
     */
    private function optionalParamValue(array $params, int $index, mixed $default): mixed
    {
        return $params[$index] ?? $default;
    }

    /**
     * @param list<mixed> $params
     */
    private function optionalStringParam(array $params, int $index, string $default): string
    {
        $value = $this->optionalParamValue($params, $index, $default);
        if (!is_string($value)) {
            throw new FileAccessException("Expected string parameter at index {$index}.");
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
            throw new FileAccessException("Write type '{$type}' expects array parameter at index {$index}.");
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
                throw new FileAccessException("Write type '{$type}' expects scalar CSV values.");
            }
            if (is_float($column) && !is_finite($column)) {
                throw new FileAccessException("Write type '{$type}' expects finite CSV values.");
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
            throw new FileAccessException("Write type '{$type}' expects string parameter at index {$index}.");
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
                throw new FileAccessException("Write type '{$type}' expects integer widths.");
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
            throw new FileAccessException("Write type '{$type}' expects SimpleXMLElement at index {$index}.");
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
    private function writeBinaryData(string $data): int|false
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
    private function writeCharacterData(string $char): int|false
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
    private function writeCsvRow(
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
     * @throws FileAccessException If the count of $data does not match the count of $widths.
     */
    private function writeFixedWidthData(array $data, array $widths): int|false
    {
        if (count($data) !== count($widths)) {
            throw new FileAccessException('Data and widths arrays must match.');
        }
        $line = '';
        foreach ($data as $index => $field) {
            $width = $widths[$index] ?? null;
            if (!is_int($width)) {
                throw new FileAccessException('Widths must contain integers.');
            }

            $line .= str_pad((string) $field, $width);
        }
        $this->writeCount++;

        return $this->requireFileHandle()->fwrite($line . PHP_EOL);
    }

    /**
     * Writes a JSON array to the file.
     *
     * @param array<int|string, mixed> $data The array of data to write.
     * @param bool $prettyPrint If true, the JSON will be formatted with
     *                          indentation and whitespace for readability. Defaults to false.
     * @return int|false The number of bytes written, or false on failure.
     * @throws FileAccessException If the JSON encoding fails.
     */
    private function writeJsonArrayData(array $data, bool $prettyPrint = false): int|false
    {
        return $this->writeJsonEncodedLine($data, $prettyPrint);
    }

    private function writeJsonEncodedLine(mixed $data, bool $prettyPrint): int|false
    {
        $jsonOptions = JSON_THROW_ON_ERROR | ($prettyPrint ? JSON_PRETTY_PRINT : 0);

        try {
            $jsonData = json_encode($data, $jsonOptions);
        } catch (\JsonException $exception) {
            throw new FileAccessException('JSON encoding failed: ' . $exception->getMessage(), 0, $exception);
        }
        $this->writeCount++;

        return $this->requireFileHandle()->fwrite($jsonData . PHP_EOL);
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
     * @throws FileAccessException If JSON encoding fails.
     */
    private function writeJsonLineData(mixed $data, bool $prettyPrint = false): int|false
    {
        return $this->writeJsonEncodedLine($data, $prettyPrint);
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
    private function writeLineData(string $content): int|false
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
    private function writeMatchingLineData(string $content, string $pattern): int|false
    {
        set_error_handler(static fn(): bool => true);

        try {
            $matched = preg_match($pattern, $content);
        } finally {
            restore_error_handler();
        }
        if ($matched === false) {
            throw new FileAccessException('Invalid regular-expression pattern.');
        }
        if ($matched === 1) {
            $this->writeCount++;

            return $this->requireFileHandle()->fwrite($content . PHP_EOL);
        }

        return 0;
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
    private function writeSerializedData(mixed $data): int|false
    {
        if (!$this->isSafeSerializedValue($data)) {
            throw new FileAccessException('Serialized values must contain only safe scalar and array types.');
        }
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
    private function writeXmlData(SimpleXMLElement $element): int|false
    {
        $xml = $element->asXML();
        if (!is_string($xml)) {
            throw new FileAccessException('XML serialization failed.');
        }
        $this->writeCount++;

        return $this->requireFileHandle()->fwrite($xml . PHP_EOL);
    }
}
