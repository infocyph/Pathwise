<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Retention;

use FilesystemIterator;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RetentionManager
{
    /**
     * Apply retention rules to a directory.
     *
     * @param string $directory The directory to apply retention rules to.
     * @param int|null $keepLast Number of most recent files to keep (null for unlimited).
     * @param int|null $maxAgeDays Maximum age of files in days (null for unlimited).
     * @param string $sortBy Field to sort by ('mtime' or 'ctime').
     * @return array{deleted: list<string>, kept: list<string>} Array with deleted and kept file paths.
     */
    public static function apply(
        string $directory,
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime',
    ): array {
        $directory = PathHelper::normalize($directory);
        if (!FlysystemHelper::directoryExists($directory)) {
            return ['deleted' => [], 'kept' => []];
        }

        $files = self::collectFiles($directory);
        usort($files, fn(array $a, array $b): int => ($b[$sortBy] ?? 0) <=> ($a[$sortBy] ?? 0));

        $kept = [];
        $deleted = [];
        $cutoff = $maxAgeDays !== null ? (time() - ($maxAgeDays * 86400)) : null;

        foreach ($files as $index => $file) {
            $shouldDeleteByCount = $keepLast !== null && $index >= $keepLast;
            $shouldDeleteByAge = $cutoff !== null && $file[$sortBy] < $cutoff;
            $path = $file['path'];

            if (($shouldDeleteByCount || $shouldDeleteByAge) && FlysystemHelper::fileExists($path)) {
                FlysystemHelper::delete($path);
                $deleted[] = $path;
            } else {
                $kept[] = $path;
            }
        }

        return [
            'deleted' => $deleted,
            'kept' => $kept,
        ];
    }

    /**
     * @return array<int, array{path: string, mtime: int, ctime: int}>
     */
    private static function collectFiles(string $directory): array
    {
        if (PathHelper::hasScheme($directory) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($directory))) {
            return self::collectFilesViaFlysystem($directory);
        }

        return self::collectFilesLocal($directory);
    }

    /**
     * @return array<int, array{path: string, mtime: int, ctime: int}>
     */
    private static function collectFilesLocal(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                continue;
            }

            $files[] = [
                'path' => $item->getPathname(),
                'mtime' => (int) $item->getMTime(),
                'ctime' => $item->getCTime(),
            ];
        }

        return $files;
    }

    /**
     * @return array<int, array{path: string, mtime: int, ctime: int}>
     */
    private static function collectFilesViaFlysystem(string $directory): array
    {
        $files = [];
        [, $baseLocation] = FlysystemHelper::resolveDirectory($directory);
        $base = trim(str_replace('\\', '/', $baseLocation), '/');

        foreach (FlysystemHelper::listContents($directory, true) as $item) {
            $entry = self::normalizeFlysystemEntry($directory, $base, $item);
            if ($entry === null) {
                continue;
            }

            $files[] = $entry;
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{path: string, mtime: int, ctime: int}|null
     */
    private static function normalizeFlysystemEntry(string $directory, string $base, array $item): ?array
    {
        $type = $item['type'] ?? null;
        if (!is_string($type) || $type !== 'file') {
            return null;
        }

        $itemPathRaw = $item['path'] ?? null;
        if (!is_string($itemPathRaw)) {
            return null;
        }

        $itemPath = trim($itemPathRaw, '/');
        if ($itemPath === '') {
            return null;
        }

        $relative = $base !== '' && str_starts_with($itemPath, $base . '/')
            ? substr($itemPath, strlen($base) + 1)
            : ($itemPath === $base ? '' : $itemPath);
        if ($relative === '') {
            return null;
        }

        $lastModified = $item['last_modified'] ?? 0;
        $mtime = is_int($lastModified)
            ? $lastModified
            : (is_numeric($lastModified) ? (int) $lastModified : 0);

        return [
            'path' => PathHelper::join($directory, $relative),
            'mtime' => $mtime,
            'ctime' => $mtime,
        ];
    }
}
