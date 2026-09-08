<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

use FilesystemIterator;
use Infocyph\Pathwise\Results\SnapshotDiff;
use Infocyph\Pathwise\Results\WatchResult;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type SnapshotEntry array{mtime: int, size: int}
 * @phpstan-type SnapshotMap array<string, SnapshotEntry>
 */
final class FileWatcher
{
    /**
     * @param SnapshotMap $previousSnapshot
     * @param SnapshotMap $currentSnapshot
     */
    public static function diff(array $previousSnapshot, array $currentSnapshot): SnapshotDiff
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

        sort($created);
        sort($modified);
        sort($deleted);

        return new SnapshotDiff($created, $modified, $deleted);
    }

    /** @return SnapshotMap */
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
            if (!$item instanceof \SplFileInfo || $item->isDir()) {
                continue;
            }

            $mtime = $item->getMTime();
            $size = $item->getSize();
            if (!is_int($mtime) || !is_int($size)) {
                continue;
            }

            $filePath = PathHelper::normalize($item->getPathname());
            $entries[$filePath] = ['mtime' => $mtime, 'size' => $size];
        }

        ksort($entries);

        return $entries;
    }

    public static function watch(
        string $path,
        callable $onChange,
        int $durationSeconds = 5,
        int $intervalMilliseconds = 500,
        bool $recursive = true,
    ): WatchResult {
        if ($durationSeconds < 1) {
            throw new InvalidArgumentException('Watcher duration must be at least one second.');
        }
        if ($intervalMilliseconds < 10) {
            throw new InvalidArgumentException('Watcher interval must be at least 10 milliseconds.');
        }

        $snapshot = self::snapshot($path, $recursive);
        $endAt = microtime(true) + $durationSeconds;
        $changeSets = 0;

        while (true) {
            $remainingMicroseconds = (int) floor(($endAt - microtime(true)) * 1_000_000);
            if ($remainingMicroseconds <= 0) {
                break;
            }

            usleep(min($intervalMilliseconds * 1000, $remainingMicroseconds));
            if (microtime(true) >= $endAt) {
                break;
            }

            $current = self::snapshot($path, $recursive);
            $diff = self::diff($snapshot, $current);
            if (!$diff->isEmpty()) {
                $onChange($diff);
                $changeSets++;
            }
            $snapshot = $current;
        }

        return new WatchResult($snapshot, $changeSets);
    }

    /** @return SnapshotMap */
    private static function snapshotViaFlysystem(string $path, bool $recursive): array
    {
        $entries = [];
        $base = FlysystemPathResolver::resolveDirectoryBase($path);

        foreach (FlysystemHelper::listContentsListing($path, $recursive) as $item) {
            $relative = FlysystemPathResolver::relativePathFromItem($item, $base, 'file');
            if ($relative === null) {
                continue;
            }

            $resolved = PathHelper::join($path, $relative);
            $lastModified = $item->lastModified() ?? 0;
            $fileSize = $item instanceof \League\Flysystem\FileAttributes ? ($item->fileSize() ?? 0) : 0;
            $entries[$resolved] = ['mtime' => $lastModified, 'size' => $fileSize];
        }

        ksort($entries);

        return $entries;
    }
}
