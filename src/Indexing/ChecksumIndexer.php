<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Indexing;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\FlysystemPathResolver;
use Infocyph\Pathwise\Utils\LocalFileIterator;
use Infocyph\Pathwise\Utils\PathHelper;

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
     * @return array{linked: list<string>, skipped: list<string>} Array with linked and skipped file paths.
     */
    public static function deduplicateWithHardLinks(string $directory, string $algorithm = 'sha256'): array
    {
        $duplicates = self::findDuplicates($directory, $algorithm);
        $linked = [];
        $skipped = [];

        foreach ($duplicates as $paths) {
            $canonical = array_shift($paths);
            if (!is_string($canonical) || !self::isLocalFile($canonical)) {
                array_push($skipped, ...$paths);

                continue;
            }

            foreach ($paths as $path) {
                if (!self::isLocalFile($path)) {
                    $skipped[] = $path;

                    continue;
                }

                $tmp = self::temporarySiblingPath($path);
                if ($tmp === null) {
                    $skipped[] = $path;

                    continue;
                }
                if (!self::runSilently(static fn(): bool => rename($path, $tmp))) {
                    $skipped[] = $path;

                    continue;
                }

                if (self::filesAreIdentical($canonical, $tmp) && self::runSilently(static fn(): bool => link($canonical, $path))) {
                    self::unlinkSilently($tmp);
                    $linked[] = $path;
                } else {
                    self::runSilently(static fn(): bool => rename($tmp, $path));
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

    private static function filesAreIdentical(string $firstPath, string $secondPath): bool
    {
        $firstSize = filesize($firstPath);
        $secondSize = filesize($secondPath);
        if (!is_int($firstSize) || !is_int($secondSize) || $firstSize !== $secondSize) {
            return false;
        }

        $first = fopen($firstPath, 'rb');
        $second = fopen($secondPath, 'rb');
        if (!is_resource($first) || !is_resource($second)) {
            if (is_resource($first)) {
                fclose($first);
            }
            if (is_resource($second)) {
                fclose($second);
            }

            return false;
        }

        try {
            while (!feof($first) && !feof($second)) {
                $firstChunk = fread($first, 65536);
                $secondChunk = fread($second, 65536);
                if (!is_string($firstChunk) || !is_string($secondChunk) || $firstChunk !== $secondChunk) {
                    return false;
                }
            }

            return feof($first) && feof($second);
        } finally {
            fclose($first);
            fclose($second);
        }
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

    /** @return \Generator<int, string> */
    private static function iterFiles(string $directory): \Generator
    {
        if (PathHelper::hasScheme($directory) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($directory))) {
            yield from self::iterFilesViaFlysystem($directory);

            return;
        }

        yield from self::iterFilesLocal($directory);
    }

    /** @return \Generator<int, string> */
    private static function iterFilesLocal(string $directory): \Generator
    {
        foreach (LocalFileIterator::files($directory) as $item) {
            yield $item->getPathname();
        }
    }

    /** @return \Generator<int, string> */
    private static function iterFilesViaFlysystem(string $directory): \Generator
    {
        $base = FlysystemPathResolver::resolveDirectoryBase($directory);

        foreach (FlysystemHelper::listContentsListing($directory, true) as $item) {
            $relative = FlysystemPathResolver::relativePathFromItem($item, $base, 'file');
            if ($relative === null) {
                continue;
            }

            yield PathHelper::join($directory, $relative);
        }
    }

    private static function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private static function temporarySiblingPath(string $path): ?string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $temporaryPath = $path . '.pathwise_' . bin2hex(random_bytes(16));
            if (!file_exists($temporaryPath)) {
                return $temporaryPath;
            }
        }

        return null;
    }

    private static function unlinkSilently(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        self::runSilently(static fn(): bool => unlink($path));
    }
}
