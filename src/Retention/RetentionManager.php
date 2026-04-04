<?php

namespace Infocyph\Pathwise\Retention;

use FilesystemIterator;
use Infocyph\Pathwise\Utils\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RetentionManager
{
    /**
     * Apply retention rules to a directory.
     *
     * @return array{deleted: array, kept: array}
     */
    public static function apply(
        string $directory,
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime'
    ): array {
        $directory = PathHelper::normalize($directory);
        if (!is_dir($directory)) {
            return ['deleted' => [], 'kept' => []];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }
            $files[] = [
                'path' => $item->getPathname(),
                'mtime' => $item->getMTime(),
                'ctime' => $item->getCTime(),
            ];
        }

        usort($files, function (array $a, array $b) use ($sortBy): int {
            return $b[$sortBy] <=> $a[$sortBy];
        });

        $kept = [];
        $deleted = [];
        $cutoff = $maxAgeDays !== null ? (time() - ($maxAgeDays * 86400)) : null;

        foreach ($files as $index => $file) {
            $shouldDeleteByCount = $keepLast !== null && $index >= $keepLast;
            $shouldDeleteByAge = $cutoff !== null && $file[$sortBy] < $cutoff;
            $path = $file['path'];

            if (($shouldDeleteByCount || $shouldDeleteByAge) && is_file($path)) {
                unlink($path);
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
}
