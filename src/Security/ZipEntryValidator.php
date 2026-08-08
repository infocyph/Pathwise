<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Security;

use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use ZipArchive;

final class ZipEntryValidator
{
    private const int UNIX_FILE_TYPE_MASK = 0170000;

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
     * @return array<int, string>
     */
    public static function validateArchive(ZipArchive $archive, string $extractionRoot): array
    {
        $entries = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->getNameIndex($index);
            if (!is_string($entry)) {
                throw new UnsafeArchiveEntryException("Unable to read ZIP entry at index {$index}.");
            }

            $entries[$index] = self::validate($entry, $extractionRoot);
            self::assertNotSymbolicLink($archive, $index, $entry);
        }

        return $entries;
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

    private static function assertNotSymbolicLink(ZipArchive $archive, int $index, string $entry): void
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
    }
}
