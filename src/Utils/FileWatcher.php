<?php

namespace Infocyph\Pathwise\Utils;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FileWatcher
{
    /**
     * Compare snapshots and return change report.
     *
     * @return array{created: array, modified: array, deleted: array}
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
            if (($old['mtime'] ?? 0) !== ($meta['mtime'] ?? 0) || ($old['size'] ?? 0) !== ($meta['size'] ?? 0)) {
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
     * @return array<string, array{mtime: int, size: int}>
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
            if ($item->isDir()) {
                continue;
            }

            $filePath = PathHelper::normalize($item->getPathname());
            $entries[$filePath] = [
                'mtime' => (int) $item->getMTime(),
                'size' => (int) $item->getSize(),
            ];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Poll for file-system changes and invoke callback on each non-empty diff.
     *
     * @return array<string, array{mtime: int, size: int}> Final snapshot.
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

    /**
     * @return array<string, array{mtime: int, size: int}>
     */
    private static function snapshotViaFlysystem(string $path, bool $recursive): array
    {
        $entries = [];
        [, $baseLocation] = FlysystemHelper::resolveDirectory($path);
        $base = trim(str_replace('\\', '/', $baseLocation), '/');

        foreach (FlysystemHelper::listContents($path, $recursive) as $item) {
            if (($item['type'] ?? null) !== 'file') {
                continue;
            }

            $itemPath = trim((string) ($item['path'] ?? ''), '/');
            if ($itemPath === '') {
                continue;
            }

            $relative = $base !== '' && str_starts_with($itemPath, $base . '/')
                ? substr($itemPath, strlen($base) + 1)
                : ($itemPath === $base ? '' : $itemPath);
            if ($relative === '') {
                continue;
            }

            $resolved = PathHelper::join($path, $relative);

            $entries[$resolved] = [
                'mtime' => (int) ($item['last_modified'] ?? 0),
                'size' => (int) ($item['file_size'] ?? 0),
            ];
        }

        ksort($entries);

        return $entries;
    }
}
