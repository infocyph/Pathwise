<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type SnapshotEntry array{mtime: int, size: int}
 * @phpstan-type SnapshotMap array<string, SnapshotEntry>
 * @phpstan-type DiffReport array{created: list<string>, modified: list<string>, deleted: list<string>}
 */
final class FileWatcher
{
    /**
     * Compare snapshots and return change report.
     *
     * @param SnapshotMap $previousSnapshot The previous snapshot data.
     * @param SnapshotMap $currentSnapshot The current snapshot data.
     * @return DiffReport The diff report with created, modified, and deleted files.
     */
    public static function diff(array $previousSnapshot, array $currentSnapshot): array
    {
        $created = [];
        $modified = [];
        $deleted = [];

        foreach ($currentSnapshot as $path => $meta) {
            if (!isset($previousSnapshot[$path])) {
                $created[] = $path;

                continue;
            }

            $old = $previousSnapshot[$path];
            if ($old['mtime'] !== $meta['mtime'] || $old['size'] !== $meta['size']) {
                $modified[] = $path;
            }
        }

        foreach ($previousSnapshot as $path => $_meta) {
            if (!isset($currentSnapshot[$path])) {
                $deleted[] = $path;
            }
        }

        return [
            'created' => $created,
            'modified' => $modified,
            'deleted' => $deleted,
        ];
    }

    /**
     * Build a snapshot map for a file or directory.
     *
     * @param string $path The path to snapshot.
     * @param bool $recursive Whether to include subdirectories recursively.
     * @return SnapshotMap The snapshot map with file paths as keys.
     */
    public static function snapshot(string $path, bool $recursive = true): array
    {
        $normalized = PathHelper::normalize($path);
        if (FlysystemHelper::fileExists($normalized)) {
            try {
                $mtime = FlysystemHelper::lastModified($normalized);
            } catch (\Throwable) {
                $mtime = 0;
            }

            return [
                $normalized => [
                    'mtime' => $mtime,
                    'size' => FlysystemHelper::size($normalized),
                ],
            ];
        }

        if (!FlysystemHelper::directoryExists($normalized)) {
            return [];
        }

        if (PathHelper::hasScheme($normalized) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($normalized))) {
            return self::snapshotViaFlysystem($normalized, $recursive);
        }

        $entries = [];
        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($normalized, FilesystemIterator::SKIP_DOTS))
            : new FilesystemIterator($normalized, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                continue;
            }

            $mtime = $item->getMTime();
            $size = $item->getSize();
            if (!is_int($mtime) || !is_int($size)) {
                continue;
            }

            $filePath = PathHelper::normalize($item->getPathname());
            $entries[$filePath] = [
                'mtime' => $mtime,
                'size' => $size,
            ];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Poll for file-system changes and invoke callback on each non-empty diff.
     *
     * @param string $path The path to watch.
     * @param callable $onChange Callback invoked when changes detected. Receives diff array.
     * @param int $durationSeconds How long to watch in seconds. Defaults to 5.
     * @param int $intervalMilliseconds Polling interval in milliseconds. Defaults to 500.
     * @param bool $recursive Whether to watch subdirectories. Defaults to true.
     * @return SnapshotMap Final snapshot.
     */
    public static function watch(
        string $path,
        callable $onChange,
        int $durationSeconds = 5,
        int $intervalMilliseconds = 500,
        bool $recursive = true,
    ): array {
        $snapshot = self::snapshot($path, $recursive);
        $endAt = microtime(true) + max(1, $durationSeconds);

        while (microtime(true) < $endAt) {
            usleep(max(10, $intervalMilliseconds) * 1000);
            $current = self::snapshot($path, $recursive);
            $diff = self::diff($snapshot, $current);

            if ($diff['created'] !== [] || $diff['modified'] !== [] || $diff['deleted'] !== []) {
                $onChange($diff);
            }

            $snapshot = $current;
        }

        return $snapshot;
    }

    private static function intFromMixed(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function resolveFlysystemRelativePath(mixed $item, string $base): ?string
    {
        if (!is_array($item)) {
            return null;
        }

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

        if ($base !== '' && str_starts_with($itemPath, $base . '/')) {
            return substr($itemPath, strlen($base) + 1);
        }

        return $itemPath === $base ? null : $itemPath;
    }

    /**
     * @return SnapshotMap
     */
    private static function snapshotViaFlysystem(string $path, bool $recursive): array
    {
        $entries = [];
        [, $baseLocation] = FlysystemHelper::resolveDirectory($path);
        $base = trim(str_replace('\\', '/', $baseLocation), '/');

        foreach (FlysystemHelper::listContents($path, $recursive) as $item) {
            $relative = self::resolveFlysystemRelativePath($item, $base);
            if ($relative === null) {
                continue;
            }

            $resolved = PathHelper::join($path, $relative);
            $lastModified = self::intFromMixed($item['last_modified'] ?? 0);
            $fileSize = self::intFromMixed($item['file_size'] ?? 0);

            $entries[$resolved] = [
                'mtime' => $lastModified,
                'size' => $fileSize,
            ];
        }

        ksort($entries);

        return $entries;
    }
}
