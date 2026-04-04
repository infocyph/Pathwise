<?php

namespace Infocyph\Pathwise\Indexing;

use FilesystemIterator;
use Infocyph\Pathwise\Utils\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ChecksumIndexer
{
    /**
     * @return array<string, array<int, string>> checksum => paths
     */
    public static function buildIndex(string $directory, string $algorithm = 'sha256'): array
    {
        $directory = PathHelper::normalize($directory);
        if (!is_dir($directory) || !in_array($algorithm, hash_algos(), true)) {
            return [];
        }

        $index = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $hash = hash_file($algorithm, $item->getPathname());
            if (!is_string($hash)) {
                continue;
            }

            $index[$hash][] = $item->getPathname();
        }

        ksort($index);

        return $index;
    }

    /**
     * Deduplicate files by replacing duplicate entries with hard links where supported.
     *
     * @return array{linked: array, skipped: array}
     */
    public static function deduplicateWithHardLinks(string $directory, string $algorithm = 'sha256'): array
    {
        $duplicates = self::findDuplicates($directory, $algorithm);
        $linked = [];
        $skipped = [];

        foreach ($duplicates as $paths) {
            $canonical = array_shift($paths);
            foreach ($paths as $path) {
                if (!is_file($canonical) || !is_file($path)) {
                    $skipped[] = $path;
                    continue;
                }

                $tmp = $path . '.tmp_delete';
                if (!@rename($path, $tmp)) {
                    $skipped[] = $path;
                    continue;
                }

                if (@link($canonical, $path)) {
                    @unlink($tmp);
                    $linked[] = $path;
                } else {
                    @rename($tmp, $path);
                    $skipped[] = $path;
                }
            }
        }

        return [
            'linked' => $linked,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function findDuplicates(string $directory, string $algorithm = 'sha256'): array
    {
        $index = self::buildIndex($directory, $algorithm);

        return array_filter($index, static fn (array $paths): bool => count($paths) > 1);
    }
}
