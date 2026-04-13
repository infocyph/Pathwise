<?php

namespace Infocyph\Pathwise\Utils;

use Infocyph\Pathwise\Utils\Ownership\OwnershipResolverFactory;
use Infocyph\Pathwise\Utils\Ownership\OwnershipResolverInterface;

class MetadataHelper
{
    private static ?OwnershipResolverInterface $ownershipResolver = null;

    /**
     * Retrieves comprehensive metadata for a given path.
     *
     * This function returns an associative array containing various metadata
     * details about the specified file or directory. The metadata includes
     * file size, file count (if a directory), timestamps, MIME type, path type,
     * ownership details, last modified by, file extension, visibility status,
     * symlink target, and symlink integrity.
     *
     * @param string $path The path to the file or directory to retrieve metadata for.
     * @param bool $humanReadableSize Optional. If true, returns the file size in a human-readable format.
     * @return array|null An associative array containing metadata information, or null if the path does not exist.
     */
    public static function getAllMetadata(string $path, bool $humanReadableSize = false): ?array
    {
        if (!PathHelper::pathExists($path)) {
            return null;
        }

        $type = self::getPathType($path);
        $isDirectory = $type === 'directory';

        return [
            'size' => $isDirectory ? self::getDirectorySize($path) : self::getFileSize($path, $humanReadableSize),
            'file_count' => $isDirectory ? self::getFileCount($path) : null,
            'timestamps' => self::getTimestamps($path),
            'human_readable_timestamps' => self::getHumanReadableTimestamps($path),
            'mime_type' => self::getMimeType($path),
            'type' => $type,
            'ownership' => self::getOwnershipDetails($path),
            'last_modified_by' => self::getLastModifiedBy($path),
            'extension' => self::getFileExtension($path),
            'is_hidden' => self::isHidden($path),
            'symlink_target' => self::getSymlinkTarget($path),
            'is_broken_symlink' => self::isBrokenSymlink($path),
        ];
    }

    /**
     * Computes the checksum of a file using the given algorithm.
     *
     * Computes the checksum of the file at the given path using the given
     * algorithm. If the path is not a file or if the algorithm is not supported,
     * returns null.
     *
     * @param string $path The path to the file to compute a checksum for.
     * @param string $algorithm The algorithm to use to compute the checksum.
     *     Supported algorithms are those that are listed in the return value
     *     of the hash_algos() function.
     * @return string|null The checksum of the file, or null if the path is not
     *     a file or if the algorithm is not supported.
     */
    public static function getChecksum(string $path, string $algorithm = 'md5'): ?string
    {
        if (!FlysystemHelper::fileExists($path) || !in_array($algorithm, hash_algos(), true)) {
            return null;
        }

        return FlysystemHelper::checksum($path, $algorithm);
    }

    /**
     * Calculates the total size of all files in the specified directory.
     *
     * This method recursively iterates over all files in the directory and sums their sizes.
     * Directories are skipped. If the directory does not exist, this method returns null.
     *
     * @param string $directory The path to the directory to calculate the size for.
     * @return int|null The total size of all files in the directory in bytes, or null if the directory does not exist.
     */
    public static function getDirectorySize(string $directory): ?int
    {
        if (!FlysystemHelper::directoryExists($directory) && !is_dir($directory)) {
            return null;
        }

        $size = 0;
        foreach (FlysystemHelper::listContents($directory, true) as $item) {
            if (($item['type'] ?? null) !== 'file') {
                continue;
            }

            $size += (int) ($item['file_size'] ?? 0);
        }

        return $size;
    }

    /**
     * Retrieves the number of files in a directory.
     *
     * This function returns the number of files in the given directory, or null
     * if the directory does not exist.
     *
     * By default, this function will recursively traverse the directory,
     * counting all files in all subdirectories. If $recursive is false, only
     * the files in the top-level directory are counted.
     *
     * @param string $directory The path to the directory to count files in.
     * @param bool $recursive If true (default), recursively traverse the
     *     directory. If false, only count files in the top-level directory.
     * @return int|null The number of files in the directory, or null if the
     *     directory does not exist.
     */
    public static function getFileCount(string $directory, bool $recursive = true): ?int
    {
        if (!FlysystemHelper::directoryExists($directory) && !is_dir($directory)) {
            return null;
        }

        $count = 0;
        foreach (FlysystemHelper::listContents($directory, $recursive) as $item) {
            if (($item['type'] ?? null) === 'file') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Returns the file extension of the specified file path.
     *
     * This method checks if the provided path is a valid file and then retrieves
     * its extension. If the path is not a file, it returns null.
     *
     * @param string $path The path to the file for which to retrieve the extension.
     * @return string|null The file extension of the specified path, or null if the path is not a file.
     */
    public static function getFileExtension(string $path): ?string
    {
        return FlysystemHelper::fileExists($path) ? pathinfo($path, PATHINFO_EXTENSION) : null;
    }

    /**
     * Retrieves the size of the specified file.
     *
     * This method returns the size of a file in bytes or in a human-readable format
     * if specified. If the path does not point to a valid file, it returns null.
     *
     * @param string $path The path to the file whose size is to be determined.
     * @param bool $humanReadable Optional. If true, returns the file size in a human-readable format.
     * @return string|int|null The size of the file in bytes, or as a formatted string if humanReadable is true, or null if the path is not a file.
     */
    public static function getFileSize(string $path, bool $humanReadable = false): string|int|null
    {
        if (!FlysystemHelper::fileExists($path)) {
            return null;
        }

        $size = FlysystemHelper::size($path);

        return $humanReadable ? self::formatSize($size) : $size;
    }

    /**
     * Retrieves the human-readable timestamps of the file at the given path.
     *
     * Returns an associative array with 'created', 'modified', and 'accessed'
     * keys, each with a value of a human-readable timestamp in the format
     * 'Y-m-d H:i:s'. If the file does not exist, returns null.
     *
     * @param string $path The path to the file to retrieve timestamps for.
     * @return array|null The human-readable timestamps, or null if the file does not exist.
     */
    public static function getHumanReadableTimestamps(string $path): ?array
    {
        $timestamps = self::getTimestamps($path);
        if (!$timestamps) {
            return null;
        }

        return array_map(fn($time) => date('Y-m-d H:i:s', $time), $timestamps);
    }

    /**
     * Returns the username of the user who last modified the specified file or directory.
     *
     * This method checks if the provided path is a valid file or directory and if
     * POSIX-style ownership functions are supported on the current system. If the
     * path is not valid or if ownership functions are not supported, it returns null.
     *
     * @param string $path The path to the file or directory for which to retrieve the last modified by.
     * @return string|null The username of the user who last modified the specified path, or null if the path is not valid or if ownership functions are not supported.
     */
    public static function getLastModifiedBy(string $path): ?string
    {
        return self::getOwnershipResolver()->getLastModifiedBy($path);
    }

    /**
     * Retrieves the MIME type of the file at the given path.
     *
     * This method first asks Flysystem for MIME metadata. For local paths, it
     * falls back to ``finfo``. If neither strategy can determine the type, null
     * is returned.
     *
     * @param string $path The path to the file to retrieve the MIME type for.
     * @return string|null The MIME type of the file, or null if the path does not
     *     point to a file or metadata cannot be determined.
     */
    public static function getMimeType(string $path): ?string
    {
        if (!FlysystemHelper::fileExists($path)) {
            return null;
        }

        try {
            $mime = FlysystemHelper::mimeType($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        } catch (\Throwable) {
            // Fall back to non-Flysystem detection chain.
        }

        if (!PathHelper::hasScheme($path) && is_file($path) && class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return null;
    }

    /**
     * Retrieves the owner and group of the given path.
     *
     * This method returns an array with keys 'owner' and 'group', each containing the
     * username or groupname of the owner or group of the file or directory,
     * respectively. If the file or directory does not exist, or if ownership
     * functions are not supported on the current system, this method returns
     * null.
     *
     * @param string $path The path to the file or directory to retrieve
     *     ownership for.
     * @return array|null An array containing the owner and group of the file
     *     or directory, or null if the file or directory does not exist, or
     *     if ownership functions are not supported on the current system.
     */
    public static function getOwnershipDetails(string $path): ?array
    {
        return self::getOwnershipResolver()->getOwnershipDetails($path);
    }

    /**
     * Determines the type of the specified path.
     *
     * This function checks if the provided path is a file, directory, or symbolic link.
     *
     * @param string $path The path to check.
     * @return string|null Returns 'file' if the path is a file, 'directory' if it's a directory,
     * 'link' if it's a symbolic link, or null if none of these.
     */
    public static function getPathType(string $path): ?string
    {
        if (FlysystemHelper::fileExists($path)) {
            return 'file';
        }

        if (FlysystemHelper::directoryExists($path)) {
            return 'directory';
        }

        return is_link($path) ? 'link' : null;
    }

    /**
     * Returns the target of the given symbolic link.
     *
     * If the given path is a symbolic link, this method returns the target of
     * the link. Otherwise, it returns null.
     *
     * @param string $path The path to the symbolic link.
     * @return string|null The target of the symbolic link, or null if the path
     *     is not a symbolic link.
     */
    public static function getSymlinkTarget(string $path): ?string
    {
        return is_link($path) ? readlink($path) : null;
    }

    /**
     * Retrieves the timestamps of the file or directory at the given path.
     *
     * This function returns an associative array with keys 'created', 'modified',
     * and 'accessed', each with a value of a Unix timestamp representing the
     * creation, last modification, and last access times of the file or
     * directory at the given path. If the file or directory does not exist,
     * this function returns null.
     *
     * @param string $path The path to the file or directory to retrieve
     *     timestamps for.
     * @return array|null The timestamps, or null if the file or directory does
     *     not exist.
     */
    public static function getTimestamps(string $path): ?array
    {
        if (file_exists($path)) {
            return [
                'created' => filectime($path),
                'modified' => filemtime($path),
                'accessed' => fileatime($path),
            ];
        }

        if (!PathHelper::pathExists($path)) {
            return null;
        }

        try {
            $modified = FlysystemHelper::lastModified($path);
        } catch (\Throwable) {
            return null;
        }

        return ['created' => $modified, 'modified' => $modified, 'accessed' => $modified];
    }

    /**
     * Determines if the given path is a broken symbolic link.
     *
     * If the path is a symbolic link, this method returns true if the target
     * of the link does not exist, or false otherwise. If the path is not a
     * symbolic link, this method returns null.
     *
     * @param string $path The path to check for a broken symbolic link.
     * @return bool|null True if the link is broken, false if it is not, or null
     *     if the path is not a symbolic link.
     */
    public static function isBrokenSymlink(string $path): ?bool
    {
        return is_link($path) ? realpath($path) === false : null;
    }

    /**
     * Determines if the given path refers to a hidden file or directory.
     *
     * This method checks if the last component of the path starts with a dot (.)
     * and returns true if it does, indicating that the path refers to a hidden
     * file or directory. Otherwise, it returns false.
     *
     * @param string $path The path to check.
     * @return bool True if the path is hidden, false otherwise.
     */
    public static function isHidden(string $path): bool
    {
        return basename($path)[0] === '.';
    }

    /**
     * Format the given size (in bytes) into a human-readable string.
     *
     * This method takes an integer representing a file size in bytes and
     * returns a string representation of that size in a human-readable format,
     * such as '1.23 KB' or '4.56 GB'. It will use the appropriate unit of
     * measurement (B, KB, MB, GB, or TB) to represent the size.
     *
     * @param int $size The size (in bytes) to be formatted.
     * @return string The human-readable representation of the given size.
     */
    private static function formatSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;
        return number_format($size / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    private static function getOwnershipResolver(): OwnershipResolverInterface
    {
        return self::$ownershipResolver ??= OwnershipResolverFactory::create();
    }
}
