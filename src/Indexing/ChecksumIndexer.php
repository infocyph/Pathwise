<?php

namespace Infocyph\Pathwise\Indexing;

use FilesystemIterator;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ChecksumIndexer
{
    /**
     * Build a checksum index for all files in a directory.
     *
     * @param string $directory The directory to index.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return array<string, array<int, string>> Array mapping checksum to array of file paths.
     */
    public static function buildIndex(string $directory, string $algorithm = 'sha256'): array
    {
        $directory = PathHelper::normalize($directory);
        if (!FlysystemHelper::directoryExists($directory) || !in_array($algorithm, hash_algos(), true)) {
            return [];
        }

        $index = [];
        foreach (self::iterFiles($directory) as $path) {
            $hash = self::hashPath($path, $algorithm);
            if (!is_string($hash)) {
                continue;
            }

            $index[$hash][] = $path;
        }

        ksort($index);

        return $index;
    }

    /**
     * Deduplicate files by replacing duplicate entries with hard links where supported.
     *
     * @param string $directory The directory to deduplicate.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return array{linked: array, skipped: array} Array with linked and skipped file paths.
     */
    public static function deduplicateWithHardLinks(string $directory, string $algorithm = 'sha256'): array
    {
        $duplicates = self::findDuplicates($directory, $algorithm);
        $linked = [];
        $skipped = [];

        foreach ($duplicates as $paths) {
            $canonical = array_shift($paths);
            foreach ($paths as $path) {
                if (!is_string($canonical) || !self::isLocalFile($canonical) || !self::isLocalFile($path)) {
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
     * Find duplicate files in a directory.
     *
     * @param string $directory The directory to search for duplicates.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return array<string, array<int, string>> Array mapping checksum to array of duplicate file paths.
     */
    public static function findDuplicates(string $directory, string $algorithm = 'sha256'): array
    {
        $index = self::buildIndex($directory, $algorithm);

        return array_filter($index, static fn(array $paths): bool => count($paths) > 1);
    }

    private static function hashPath(string $path, string $algorithm): ?string
    {
        if (self::isLocalFile($path)) {
            $hash = hash_file($algorithm, $path);

            return is_string($hash) ? $hash : null;
        }

        $stream = FlysystemHelper::readStream($path);
        if (!is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init($algorithm);
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private static function isLocalFile(string $path): bool
    {
        return !PathHelper::hasScheme($path) && is_file($path);
    }

    /**
     * @return array<int, string>
     */
    private static function iterFiles(string $directory): array
    {
        if (PathHelper::hasScheme($directory) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($directory))) {
            return self::iterFilesViaFlysystem($directory);
        }

        return self::iterFilesLocal($directory);
    }

    /**
     * @return array<int, string>
     */
    private static function iterFilesLocal(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $paths[] = $item->getPathname();
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private static function iterFilesViaFlysystem(string $directory): array
    {
        $paths = [];
        [, $baseLocation] = FlysystemHelper::resolveDirectory($directory);
        $base = trim(str_replace('\\', '/', $baseLocation), '/');

        foreach (FlysystemHelper::listContents($directory, true) as $item) {
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

            $paths[] = PathHelper::join($directory, $relative);
        }

        return $paths;
    }
}
