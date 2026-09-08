<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Retention;

use Infocyph\Pathwise\Results\RetentionResult;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\FlysystemPathResolver;
use Infocyph\Pathwise\Utils\LocalFileIterator;
use Infocyph\Pathwise\Utils\PathHelper;
use InvalidArgumentException;

final class RetentionManager
{
    public static function apply(
        string $directory,
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime',
    ): RetentionResult {
        return self::evaluate($directory, $keepLast, $maxAgeDays, $sortBy, true);
    }

    /**
     * Return the exact retention decision without deleting files.
     */
    public static function preview(
        string $directory,
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime',
    ): RetentionResult {
        return self::evaluate($directory, $keepLast, $maxAgeDays, $sortBy, false);
    }

    /** @return array<int, array{path: string, mtime: int, ctime: int|null}> */
    private static function collectFiles(string $directory): array
    {
        if (PathHelper::hasScheme($directory) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($directory))) {
            return self::collectFilesViaFlysystem($directory);
        }

        return self::collectFilesLocal($directory);
    }

    /** @return array<int, array{path: string, mtime: int, ctime: int|null}> */
    private static function collectFilesLocal(string $directory): array
    {
        $files = [];
        foreach (LocalFileIterator::files($directory) as $item) {
            $files[] = [
                'path' => $item->getPathname(),
                'mtime' => (int) $item->getMTime(),
                'ctime' => $item->getCTime(),
            ];
        }

        return $files;
    }

    /** @return array<int, array{path: string, mtime: int, ctime: int|null}> */
    private static function collectFilesViaFlysystem(string $directory): array
    {
        $files = [];
        $base = FlysystemPathResolver::resolveDirectoryBase($directory);

        foreach (FlysystemHelper::listContentsListing($directory, true) as $item) {
            $entry = self::normalizeFlysystemEntry($directory, $base, $item);
            if ($entry !== null) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /** @param list<string> $paths */
    private static function deletePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (FlysystemHelper::fileExists($path)) {
                FlysystemHelper::delete($path);
            }
        }
    }

    private static function evaluate(
        string $directory,
        ?int $keepLast,
        ?int $maxAgeDays,
        string $sortBy,
        bool $delete,
    ): RetentionResult {
        self::validateOptions($keepLast, $maxAgeDays, $sortBy);
        $directory = PathHelper::normalize($directory);

        if ($sortBy === 'ctime' && !FlysystemHelper::isLocalPath($directory)) {
            throw new InvalidArgumentException('ctime retention is unavailable for adapter-backed storage.');
        }
        if (!FlysystemHelper::directoryExists($directory)) {
            return new RetentionResult([], []);
        }

        $decision = self::partitionFiles(
            self::sortFiles(self::collectFiles($directory), $sortBy),
            $keepLast,
            $maxAgeDays,
            $sortBy,
        );
        if ($delete) {
            self::deletePaths($decision['deleted']);
        }

        return new RetentionResult($decision['deleted'], $decision['kept']);
    }

    /** @return array{path: string, mtime: int, ctime: int|null}|null */
    private static function normalizeFlysystemEntry(
        string $directory,
        string $base,
        \League\Flysystem\StorageAttributes $item,
    ): ?array {
        $relative = FlysystemPathResolver::relativePathFromItem($item, $base, 'file');
        if ($relative === null) {
            return null;
        }

        return [
            'path' => PathHelper::join($directory, $relative),
            'mtime' => $item->lastModified() ?? 0,
            'ctime' => null,
        ];
    }

    /**
     * @param array<int, array{path: string, mtime: int, ctime: int|null}> $files
     * @return array{deleted: list<string>, kept: list<string>}
     */
    private static function partitionFiles(
        array $files,
        ?int $keepLast,
        ?int $maxAgeDays,
        string $sortBy,
    ): array {
        $deleted = [];
        $kept = [];
        $cutoff = $maxAgeDays !== null ? time() - ($maxAgeDays * 86400) : null;

        foreach ($files as $index => $file) {
            $deleteByCount = $keepLast !== null && $index >= $keepLast;
            $deleteByAge = $cutoff !== null && $file[$sortBy] < $cutoff;
            if ($deleteByCount || $deleteByAge) {
                $deleted[] = $file['path'];

                continue;
            }

            $kept[] = $file['path'];
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /**
     * @param array<int, array{path: string, mtime: int, ctime: int|null}> $files
     * @return array<int, array{path: string, mtime: int, ctime: int|null}>
     */
    private static function sortFiles(array $files, string $sortBy): array
    {
        usort($files, static function (array $first, array $second) use ($sortBy): int {
            $comparison = $second[$sortBy] <=> $first[$sortBy];

            return $comparison !== 0 ? $comparison : strcmp($first['path'], $second['path']);
        });

        return $files;
    }

    private static function validateOptions(?int $keepLast, ?int $maxAgeDays, string $sortBy): void
    {
        if ($keepLast !== null && $keepLast < 0) {
            throw new InvalidArgumentException('keepLast must be null or greater than or equal to zero.');
        }
        if ($maxAgeDays !== null && $maxAgeDays < 0) {
            throw new InvalidArgumentException('maxAgeDays must be null or greater than or equal to zero.');
        }
        if ($sortBy !== 'mtime' && $sortBy !== 'ctime') {
            throw new InvalidArgumentException("Unsupported retention sort field: {$sortBy}.");
        }
    }
}
