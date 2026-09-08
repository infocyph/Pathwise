<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Security;

use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use ZipArchive;

final class ZipEntryValidator
{
    public const float DEFAULT_MAX_COMPRESSION_RATIO = 1_000.0;

    public const int DEFAULT_MAX_ENTRIES = 10_000;

    public const int DEFAULT_MAX_ENTRY_UNCOMPRESSED_BYTES = 1_073_741_824;

    public const int DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES = 4_294_967_296;

    private const int UNIX_BLOCK_DEVICE = 0060000;

    private const int UNIX_CHARACTER_DEVICE = 0020000;

    private const int UNIX_FIFO = 0010000;

    private const int UNIX_FILE_TYPE_MASK = 0170000;

    private const int UNIX_SOCKET = 0140000;

    private const int UNIX_SYMBOLIC_LINK = 0120000;

    public static function validate(string $entry, string $extractionRoot): string
    {
        if ($entry === '' || str_contains($entry, "\0")) {
            throw new UnsafeArchiveEntryException("Unsafe ZIP entry path detected: {$entry}");
        }

        $normalized = str_replace('\\', '/', $entry);
        if (
            str_starts_with($normalized, '/')
            || str_starts_with($normalized, '//')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
        ) {
            throw new UnsafeArchiveEntryException("Absolute ZIP entry path detected: {$entry}");
        }

        $segments = explode('/', $normalized);
        $safeSegments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new UnsafeArchiveEntryException("ZIP traversal entry detected: {$entry}");
            }

            $safeSegments[] = $segment;
        }

        if ($safeSegments === []) {
            throw new UnsafeArchiveEntryException("Empty ZIP entry path detected: {$entry}");
        }

        $relative = implode('/', $safeSegments);
        $root = rtrim(str_replace('\\', '/', $extractionRoot), '/');
        $destination = $root . '/' . $relative;
        if ($destination !== $root && !str_starts_with($destination, $root . '/')) {
            throw new UnsafeArchiveEntryException("ZIP entry escapes extraction root: {$entry}");
        }
        self::assertNoSymbolicLinkInDestination($root, $safeSegments, $entry);

        return str_ends_with($normalized, '/') ? $relative . '/' : $relative;
    }

    /**
     * @return array<int, ZipArchiveManifestEntry>
     */
    public static function validateArchive(
        ZipArchive $archive,
        string $extractionRoot,
        int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        int $maxEntryUncompressedBytes = self::DEFAULT_MAX_ENTRY_UNCOMPRESSED_BYTES,
        int $maxTotalUncompressedBytes = self::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES,
        float $maxCompressionRatio = self::DEFAULT_MAX_COMPRESSION_RATIO,
    ): array {
        self::validateArchiveLimits(
            $maxEntries,
            $maxEntryUncompressedBytes,
            $maxTotalUncompressedBytes,
            $maxCompressionRatio,
        );
        if ($maxEntries > 0 && $archive->numFiles > $maxEntries) {
            throw new UnsafeArchiveEntryException(
                "ZIP archive contains {$archive->numFiles} entries; the configured maximum is {$maxEntries}.",
            );
        }

        $entries = [];
        $seenPaths = [];
        $filePaths = [];
        $ancestorPaths = [];
        $totalUncompressedBytes = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $archiveName = $archive->getNameIndex($index);
            if (!is_string($archiveName)) {
                throw new UnsafeArchiveEntryException("Unable to read ZIP entry at index {$index}.");
            }

            $path = self::validate($archiveName, $extractionRoot);
            $directory = str_ends_with($path, '/');
            self::assertSupportedEntryType($archive, $index, $archiveName);
            self::assertNoPathConflict($path, $directory, $seenPaths, $filePaths, $ancestorPaths, $archiveName);

            [$uncompressedBytes, $totalUncompressedBytes] = self::validateEntryResources(
                $archive,
                $index,
                $archiveName,
                $totalUncompressedBytes,
                $maxEntryUncompressedBytes,
                $maxTotalUncompressedBytes,
                $maxCompressionRatio,
            );

            $entries[$index] = new ZipArchiveManifestEntry(
                index: $index,
                archiveName: $archiveName,
                path: $path,
                uncompressedBytes: $uncompressedBytes,
                directory: $directory,
            );
        }

        return $entries;
    }

    public static function validateArchiveLimits(
        int $maxEntries,
        int $maxEntryUncompressedBytes,
        int $maxTotalUncompressedBytes,
        float $maxCompressionRatio,
    ): void {
        if (
            $maxEntries < 0
            || $maxEntryUncompressedBytes < 0
            || $maxTotalUncompressedBytes < 0
            || $maxCompressionRatio < 0
            || !is_finite($maxCompressionRatio)
        ) {
            throw new \InvalidArgumentException('ZIP extraction limits must be finite, non-negative values.');
        }
    }

    /**
     * @param array<string, bool> $seenPaths
     * @param array<string, true> $filePaths
     * @param array<string, true> $ancestorPaths
     */
    private static function assertNoPathConflict(
        string $path,
        bool $directory,
        array &$seenPaths,
        array &$filePaths,
        array &$ancestorPaths,
        string $entry,
    ): void {
        $canonical = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        if (isset($seenPaths[$canonical])) {
            throw new UnsafeArchiveEntryException("Duplicate or case-conflicting ZIP entry detected: {$entry}");
        }
        if (!$directory && isset($ancestorPaths[$canonical])) {
            throw new UnsafeArchiveEntryException("ZIP file conflicts with an archive directory path: {$entry}");
        }

        $segments = explode('/', $canonical);
        $ancestor = '';
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            $ancestor = $ancestor === '' ? $segment : $ancestor . '/' . $segment;
            if ($index === $lastIndex) {
                break;
            }
            if (isset($filePaths[$ancestor])) {
                throw new UnsafeArchiveEntryException("ZIP entry is nested below an archive file: {$entry}");
            }
            $ancestorPaths[$ancestor] = true;
        }

        $seenPaths[$canonical] = $directory;
        if (!$directory) {
            $filePaths[$canonical] = true;
        }
    }

    /** @param list<string> $segments */
    private static function assertNoSymbolicLinkInDestination(string $root, array $segments, string $entry): void
    {
        if (str_contains($root, '://')) {
            return;
        }

        $candidate = $root;
        if (is_link($candidate)) {
            throw new UnsafeArchiveEntryException("Extraction root is a symbolic link for ZIP entry: {$entry}");
        }

        foreach ($segments as $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                throw new UnsafeArchiveEntryException("ZIP destination traverses a symbolic link: {$entry}");
            }
        }
    }

    private static function assertSupportedEntryType(ZipArchive $archive, int $index, string $entry): void
    {
        $attributes = 0;
        $operationsSystem = 0;
        if (!$archive->getExternalAttributesIndex($index, $operationsSystem, $attributes)) {
            return;
        }
        if (!is_int($attributes)) {
            throw new UnsafeArchiveEntryException("Unable to validate ZIP entry attributes: {$entry}");
        }

        $mode = ($attributes >> 16) & self::UNIX_FILE_TYPE_MASK;
        if ($mode === self::UNIX_SYMBOLIC_LINK) {
            throw new UnsafeArchiveEntryException("Symbolic-link ZIP entry detected: {$entry}");
        }
        if (in_array($mode, [self::UNIX_BLOCK_DEVICE, self::UNIX_CHARACTER_DEVICE, self::UNIX_FIFO, self::UNIX_SOCKET], true)) {
            throw new UnsafeArchiveEntryException("Special-file ZIP entry detected: {$entry}");
        }
    }

    /** @return array{int, int} */
    private static function validateEntryResources(
        ZipArchive $archive,
        int $index,
        string $entry,
        int $currentTotal,
        int $maxEntryUncompressedBytes,
        int $maxTotalUncompressedBytes,
        float $maxCompressionRatio,
    ): array {
        $stat = $archive->statIndex($index);
        if (!is_array($stat)) {
            throw new UnsafeArchiveEntryException("Unable to read ZIP resource metadata for entry: {$entry}");
        }

        $size = $stat['size'];
        $compressedSize = $stat['comp_size'];
        if ($size < 0 || $compressedSize < 0) {
            throw new UnsafeArchiveEntryException("Invalid ZIP resource metadata for entry: {$entry}");
        }
        if ($maxEntryUncompressedBytes > 0 && $size > $maxEntryUncompressedBytes) {
            throw new UnsafeArchiveEntryException(
                "ZIP entry exceeds the configured uncompressed-size limit: {$entry}",
            );
        }
        if ($size > PHP_INT_MAX - $currentTotal) {
            throw new UnsafeArchiveEntryException('ZIP archive uncompressed size exceeds the platform integer range.');
        }

        $total = $currentTotal + $size;
        if ($maxTotalUncompressedBytes > 0 && $total > $maxTotalUncompressedBytes) {
            throw new UnsafeArchiveEntryException('ZIP archive exceeds the configured total uncompressed-size limit.');
        }
        if (
            $maxCompressionRatio > 0
            && $size > 0
            && ($compressedSize === 0 || ($size / $compressedSize) > $maxCompressionRatio)
        ) {
            throw new UnsafeArchiveEntryException(
                "ZIP entry exceeds the configured compression-ratio limit: {$entry}",
            );
        }

        return [$size, $total];
    }
}
