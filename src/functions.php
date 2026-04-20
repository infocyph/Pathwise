<?php

declare(strict_types=1);

use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;

if (!function_exists('getHumanReadableFileSize')) {
    /**
     * Format the given size (in bytes) into a human-readable string.
     *
     * This method takes an integer representing a file size in bytes and
     * returns a string representation of that size in a human-readable format,
     * such as '1.23 KB' or '4.56 GB'. It will use the appropriate unit of
     * measurement (B, KB, MB, GB, or TB) to represent the size.
     *
     * @param int $sizeInBytes The size of the file in bytes.
     * @return string The human-readable representation of the given size.
     */
    function getHumanReadableFileSize(int $sizeInBytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $sizeInBytes > 0 ? (int) floor(log($sizeInBytes, 1024)) : 0;

        return number_format($sizeInBytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}

if (!function_exists('isDirectoryEmpty')) {
    /**
     * Check if a directory is empty.
     *
     * @param string $directoryPath The directory path.
     * @return bool True if empty, false otherwise.
     */
    function isDirectoryEmpty(string $directoryPath): bool
    {
        $isLocalDirectory = !PathHelper::hasScheme($directoryPath) && is_dir($directoryPath);
        if (!$isLocalDirectory && !FlysystemHelper::directoryExists($directoryPath)) {
            throw new InvalidArgumentException('The provided path is not a directory.');
        }
        $contents = FlysystemHelper::listContents($directoryPath, false);

        return count($contents) === 0;
    }
}

if (!function_exists('deleteDirectory')) {
    /**
     * Delete a directory and its contents recursively.
     *
     * @param string $directoryPath The directory path.
     * @return bool True if successful, false otherwise.
     */
    function deleteDirectory(string $directoryPath): bool
    {
        $isLocalDirectory = !PathHelper::hasScheme($directoryPath) && is_dir($directoryPath);
        if (!$isLocalDirectory && !FlysystemHelper::directoryExists($directoryPath)) {
            return false;
        }

        FlysystemHelper::deleteDirectory($directoryPath);

        return !FlysystemHelper::directoryExists($directoryPath);
    }
}

if (!function_exists('getDirectorySize')) {
    /**
     * Get the size of a directory recursively.
     *
     * @param string $directoryPath The directory path.
     * @return int The total size of the directory in bytes.
     */
    function getDirectorySize(string $directoryPath): int
    {
        $isLocalDirectory = !PathHelper::hasScheme($directoryPath) && is_dir($directoryPath);
        if (!$isLocalDirectory && !FlysystemHelper::directoryExists($directoryPath)) {
            throw new InvalidArgumentException('The provided path is not a directory.');
        }

        $size = 0;
        foreach (FlysystemHelper::listContentsListing($directoryPath, true) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            if ($item instanceof FileAttributes && is_int($item->fileSize())) {
                $size += $item->fileSize();

                continue;
            }

            $extra = $item->extraMetadata();
            $extraSize = $extra['file_size'] ?? $extra['filesize'] ?? 0;
            if (is_int($extraSize)) {
                $size += $extraSize;
            } elseif (is_numeric($extraSize)) {
                $size += (int) $extraSize;
            }
        }

        return $size;
    }
}

if (!function_exists('createDirectory')) {
    /**
     * Create a directory if it doesn't exist.
     *
     * @param string $directoryPath The directory path.
     * @param int $permissions Permissions for the directory (default 0755).
     * @return bool True if successful, false otherwise.
     */
    function createDirectory(string $directoryPath, int $permissions = 0755): bool
    {
        $isLocalDirectory = !PathHelper::hasScheme($directoryPath) && is_dir($directoryPath);
        if ($isLocalDirectory || FlysystemHelper::directoryExists($directoryPath)) {
            return true;
        }

        FlysystemHelper::createDirectory($directoryPath);
        if (!PathHelper::hasScheme($directoryPath)) {
            set_error_handler(static fn(): bool => true);

            try {
                chmod($directoryPath, $permissions);
            } finally {
                restore_error_handler();
            }
        }

        return true;
    }
}

if (!function_exists('listFiles')) {
    /**
     * List all files in a directory.
     *
     * @param string $directoryPath The directory path.
     * @return list<string> List of files (excluding directories).
     */
    function listFiles(string $directoryPath): array
    {
        $isLocalDirectory = !PathHelper::hasScheme($directoryPath) && is_dir($directoryPath);
        if (!$isLocalDirectory && !FlysystemHelper::directoryExists($directoryPath)) {
            throw new InvalidArgumentException('The provided path is not a directory.');
        }
        $items = FlysystemHelper::listContents($directoryPath, false);
        $files = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? null;
            if (!is_string($type) || $type !== 'file') {
                continue;
            }

            $path = $item['path'] ?? null;
            if (!is_string($path) || $path === '') {
                continue;
            }

            $files[] = basename($path);
        }

        return $files;
    }
}

if (!function_exists('copyDirectory')) {
    /**
     * Copy a directory and its contents recursively.
     *
     * @param string $source The source directory.
     * @param string $destination The destination directory.
     * @return bool True if successful, false otherwise.
     */
    function copyDirectory(string $source, string $destination): bool
    {
        $isLocalSource = !PathHelper::hasScheme($source) && is_dir($source);
        if (!$isLocalSource && !FlysystemHelper::directoryExists($source)) {
            return false;
        }

        FlysystemHelper::copyDirectory($source, $destination);

        return true;
    }
}

if (!function_exists('createFilesystem')) {
    /**
     * Build a Flysystem filesystem from configuration.
     *
     * Supported inputs:
     * - ['driver' => 'local', 'root' => '/path']
     * - ['filesystem' => $filesystemOperator]
     * - ['adapter' => $flysystemAdapter]
     * - ['driver' => 'custom', ...] after StorageFactory::registerDriver()
     *
     * @param array<string, mixed> $config The filesystem configuration.
     * @return FilesystemOperator The created filesystem.
     */
    function createFilesystem(array $config): FilesystemOperator
    {
        return StorageFactory::createFilesystem($config);
    }
}

if (!function_exists('mountStorage')) {
    /**
     * Build and mount a filesystem under a scheme name.
     *
     * @param string $name The mount name.
     * @param array<string, mixed> $config The filesystem configuration.
     * @return FilesystemOperator The created filesystem.
     */
    function mountStorage(string $name, array $config): FilesystemOperator
    {
        return StorageFactory::mount($name, $config);
    }
}

if (!function_exists('mountStorages')) {
    /**
     * Build and mount multiple filesystems.
     *
     * @param array<string, array<string, mixed>> $mounts Array of mount name => config pairs.
     */
    function mountStorages(array $mounts): void
    {
        StorageFactory::mountMany($mounts);
    }
}
