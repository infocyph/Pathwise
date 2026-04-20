<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

use DateTimeInterface;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;

/**
 * @phpstan-type FsConfig array<string, mixed>
 */
final class FlysystemHelper
{
    private static ?FilesystemOperator $defaultFilesystem = null;

    /** @var array<string, FilesystemOperator> */
    private static array $mounts = [];

    /**
     * Calculate the checksum of a file.
     *
     * @param string $path The file path.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @param FsConfig $config Additional configuration.
     * @return string|null The checksum, or null if the file doesn't exist or algorithm is unsupported.
     */
    public static function checksum(string $path, string $algorithm = 'sha256', array $config = []): ?string
    {
        if (!in_array($algorithm, hash_algos(), true) || !self::fileExists($path)) {
            return null;
        }

        [$filesystem, $location] = self::filesystemForFile($path);

        $config['checksum_algo'] = $algorithm;

        return $filesystem->checksum($location, $config);
    }

    /**
     * Clear the default filesystem.
     */
    public static function clearDefaultFilesystem(): void
    {
        self::$defaultFilesystem = null;
    }

    /**
     * Clear all mounted filesystems.
     */
    public static function clearMounts(): void
    {
        self::$mounts = [];
    }

    /**
     * Copy a file from source to destination.
     *
     * @param string $source The source file path.
     * @param string $destination The destination file path.
     * @param FsConfig $config Additional configuration.
     */
    public static function copy(string $source, string $destination, array $config = []): void
    {
        [$destinationFilesystem, $destinationLocation] = self::filesystemForFile($destination);

        // If source and destination resolve to the same filesystem, delegate copy operation directly.
        [$sourceFilesystem, $sourceLocation] = self::filesystemForFile($source);
        if ($sourceFilesystem === $destinationFilesystem) {
            $sourceFilesystem->copy($sourceLocation, $destinationLocation, $config);

            return;
        }
        $stream = $sourceFilesystem->readStream($sourceLocation);

        if (!is_resource($stream)) {
            throw new \RuntimeException("Unable to read source stream: {$source}");
        }

        try {
            $destinationFilesystem->writeStream($destinationLocation, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Copy a directory recursively from source to destination.
     *
     * @param string $source The source directory path.
     * @param string $destination The destination directory path.
     * @param FsConfig $config Additional configuration.
     */
    public static function copyDirectory(string $source, string $destination, array $config = []): void
    {
        [$sourceFilesystem, $sourceLocation] = self::filesystemForDirectory($source);
        [$destinationFilesystem, $destinationLocation] = self::filesystemForDirectory($destination);
        $destinationFilesystem->createDirectory($destinationLocation, $config);

        $listing = $sourceFilesystem->listContents($sourceLocation, true);
        foreach ($listing as $item) {
            $itemPath = str_replace('\\', '/', $item->path());
            $relative = $sourceLocation === ''
                ? ltrim($itemPath, '/')
                : ltrim(substr($itemPath, strlen(rtrim($sourceLocation, '/')) + 1), '/');

            if ($relative === '') {
                continue;
            }

            $targetPath = trim($destinationLocation . '/' . $relative, '/');

            if ($item->isDir()) {
                $destinationFilesystem->createDirectory($targetPath, $config);

                continue;
            }

            $stream = $sourceFilesystem->readStream($itemPath);
            if (!is_resource($stream)) {
                throw new \RuntimeException("Unable to read stream for '{$itemPath}'.");
            }

            try {
                $destinationFilesystem->writeStream($targetPath, $stream, $config);
            } finally {
                fclose($stream);
            }
        }
    }

    /**
     * Create a directory.
     *
     * @param string $path The directory path.
     * @param FsConfig $config Additional configuration.
     */
    public static function createDirectory(string $path, array $config = []): void
    {
        [$filesystem, $location] = self::filesystemForDirectory($path);
        $filesystem->createDirectory($location, $config);
    }

    /**
     * Delete a file.
     *
     * @param string $path The file path.
     */
    public static function delete(string $path): void
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        $filesystem->delete($location);
    }

    /**
     * Delete a directory.
     *
     * @param string $path The directory path.
     */
    public static function deleteDirectory(string $path): void
    {
        [$filesystem, $location] = self::filesystemForDirectory($path);
        $filesystem->deleteDirectory($location);
    }

    /**
     * Check if a directory exists.
     *
     * @param string $path The directory path.
     * @return bool True if the directory exists, false otherwise.
     */
    public static function directoryExists(string $path): bool
    {
        try {
            [$filesystem, $location] = self::filesystemForDirectory($path);

            return $filesystem->directoryExists($location);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a file exists.
     *
     * @param string $path The file path.
     * @return bool True if the file exists, false otherwise.
     */
    public static function fileExists(string $path): bool
    {
        try {
            [$filesystem, $location] = self::filesystemForFile($path);

            return $filesystem->fileExists($location);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a path exists (file or directory).
     *
     * @param string $path The path to check.
     * @return bool True if the path exists, false otherwise.
     */
    public static function has(string $path): bool
    {
        try {
            [$filesystem, $location] = self::filesystemForPath($path);

            return $filesystem->has($location);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a default filesystem is set.
     *
     * @return bool True if a default filesystem is set, false otherwise.
     */
    public static function hasDefaultFilesystem(): bool
    {
        return self::$defaultFilesystem !== null;
    }

    /**
     * Get the last modified timestamp of a file.
     *
     * @param string $path The file path.
     * @return int The last modified timestamp.
     */
    public static function lastModified(string $path): int
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->lastModified($location);
    }

    /**
     * List directory contents as an array.
     *
     * @param string $path The directory path.
     * @param bool $deep Whether to list recursively. Defaults to true.
     * @return list<array<string, mixed>> The list of contents.
     */
    public static function listContents(string $path, bool $deep = true): array
    {
        $items = [];
        foreach (self::listContentsListing($path, $deep) as $item) {
            $items[] = self::normalizeStorageAttributes($item);
        }

        return $items;
    }

    /**
     * List directory contents as a DirectoryListing.
     *
     * @param string $path The directory path.
     * @param bool $deep Whether to list recursively.
     * @return DirectoryListing<StorageAttributes> The directory listing.
     */
    public static function listContentsListing(string $path, bool $deep = true): DirectoryListing
    {
        [$filesystem, $location] = self::filesystemForDirectory($path);

        return $filesystem->listContents($location, $deep);
    }

    /**
     * Get the MIME type of a file.
     *
     * @param string $path The file path.
     * @return string|null The MIME type, or null if the file doesn't exist.
     */
    public static function mimeType(string $path): ?string
    {
        if (!self::fileExists($path)) {
            return null;
        }

        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->mimeType($location);
    }

    /**
     * Mount a filesystem under a name.
     *
     * @param string $name The mount name.
     * @param FilesystemOperator $filesystem The filesystem to mount.
     */
    public static function mount(string $name, FilesystemOperator $filesystem): void
    {
        $normalized = self::normalizeMountName($name);
        self::$mounts[$normalized] = $filesystem;
    }

    /**
     * Move a file from source to destination.
     *
     * @param string $source The source file path.
     * @param string $destination The destination file path.
     * @param FsConfig $config Additional configuration.
     */
    public static function move(string $source, string $destination, array $config = []): void
    {
        [$destinationFilesystem, $destinationLocation] = self::filesystemForFile($destination);
        [$sourceFilesystem, $sourceLocation] = self::filesystemForFile($source);

        if ($sourceFilesystem === $destinationFilesystem) {
            $sourceFilesystem->move($sourceLocation, $destinationLocation, $config);

            return;
        }

        self::copy($source, $destination, $config);
        self::delete($source);
    }

    /**
     * Move a directory from source to destination.
     *
     * @param string $source The source directory path.
     * @param string $destination The destination directory path.
     * @param FsConfig $config Additional configuration.
     */
    public static function moveDirectory(string $source, string $destination, array $config = []): void
    {
        self::copyDirectory($source, $destination, $config);
        self::deleteDirectory($source);
    }

    /**
     * Get the public URL for a file.
     *
     * @param string $path The file path.
     * @param FsConfig $config Additional configuration for URL generation.
     * @return string The public URL.
     * @throws \RuntimeException If public URL generation fails.
     */
    public static function publicUrl(string $path, array $config = []): string
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        try {
            return $filesystem->publicUrl($location, $config);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Public URL generation failed for the resolved filesystem.', 0, $e);
        }
    }

    /**
     * Read the contents of a file.
     *
     * @param string $path The file path.
     * @return string The file contents.
     */
    public static function read(string $path): string
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->read($location);
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path The file path.
     * @return mixed The file stream resource.
     */
    public static function readStream(string $path): mixed
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->readStream($location);
    }

    /**
     * Reset the helper by clearing default filesystem and mounts.
     */
    public static function reset(): void
    {
        self::clearDefaultFilesystem();
        self::clearMounts();
    }

    /**
     * Resolve a path to the Flysystem operator and relative location.
     *
     * @return array{FilesystemOperator, string}
     */
    public static function resolve(string $path): array
    {
        return self::filesystemForPath($path);
    }

    /**
     * Resolve a directory path to the Flysystem operator and relative location.
     *
     * @return array{FilesystemOperator, string}
     */
    public static function resolveDirectory(string $path): array
    {
        return self::filesystemForDirectory($path);
    }

    /**
     * Set the default filesystem.
     *
     * @param FilesystemOperator $filesystem The filesystem to set as default.
     */
    public static function setDefaultFilesystem(FilesystemOperator $filesystem): void
    {
        self::$defaultFilesystem = $filesystem;
    }

    /**
     * Set the visibility of a file.
     *
     * @param string $path The file path.
     * @param string $visibility The visibility to set (e.g., 'public' or 'private').
     */
    public static function setVisibility(string $path, string $visibility): void
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        $filesystem->setVisibility($location, $visibility);
    }

    /**
     * Get the size of a file.
     *
     * @param string $path The file path.
     * @return int The file size in bytes.
     */
    public static function size(string $path): int
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->fileSize($location);
    }

    /**
     * Get a temporary URL for a file.
     *
     * @param string $path The file path.
     * @param DateTimeInterface $expiresAt The expiration time.
     * @param FsConfig $config Additional configuration for URL generation.
     * @return string The temporary URL.
     * @throws \RuntimeException If temporary URL generation fails.
     */
    public static function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        try {
            return $filesystem->temporaryUrl($location, $expiresAt, $config);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Temporary URL generation failed for the resolved filesystem.', 0, $e);
        }
    }

    /**
     * Unmount a filesystem.
     *
     * @param string $name The mount name.
     */
    public static function unmount(string $name): void
    {
        $normalized = self::normalizeMountName($name);
        unset(self::$mounts[$normalized]);
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path The file path.
     * @return string The visibility.
     */
    public static function visibility(string $path): string
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->visibility($location);
    }

    /**
     * Write contents to a file.
     *
     * @param string $path The file path.
     * @param string $contents The contents to write.
     * @param FsConfig $config Additional configuration.
     */
    public static function write(string $path, string $contents, array $config = []): void
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        $filesystem->write($location, $contents, $config);
    }

    /**
     * Write to a file from a stream.
     *
     * @param string $path The file path.
     * @param mixed $stream The stream resource.
     * @param FsConfig $config Additional configuration.
     */
    public static function writeStream(string $path, mixed $stream, array $config = []): void
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        $filesystem->writeStream($location, $stream, $config);
    }

    /**
     * @return array{FilesystemOperator, string}
     */
    private static function filesystemForDirectory(string $path): array
    {
        [$mountedFilesystem, $mountedLocation] = self::resolveMountedFilesystem($path);
        if ($mountedFilesystem !== null) {
            return [$mountedFilesystem, rtrim($mountedLocation, '/')];
        }

        if (self::$defaultFilesystem !== null && !PathHelper::isAbsolute($path)) {
            return [self::$defaultFilesystem, trim(str_replace('\\', '/', $path), '/')];
        }

        $path = PathHelper::normalize(rtrim($path, '/\\'));

        if ($path === '' || $path === DIRECTORY_SEPARATOR) {
            return [new Filesystem(new LocalFilesystemAdapter(DIRECTORY_SEPARATOR)), ''];
        }

        $parent = dirname($path);
        $location = basename($path);

        return [
            new Filesystem(new LocalFilesystemAdapter($parent)),
            str_replace('\\', '/', $location),
        ];
    }

    /**
     * @return array{FilesystemOperator, string}
     */
    private static function filesystemForFile(string $path): array
    {
        [$mountedFilesystem, $mountedLocation] = self::resolveMountedFilesystem($path);
        if ($mountedFilesystem !== null) {
            return [$mountedFilesystem, ltrim($mountedLocation, '/')];
        }

        if (self::$defaultFilesystem !== null && !PathHelper::isAbsolute($path)) {
            return [self::$defaultFilesystem, ltrim(str_replace('\\', '/', $path), '/')];
        }

        $path = PathHelper::normalize($path);
        $directory = dirname($path);
        $location = basename($path);

        return [
            new Filesystem(new LocalFilesystemAdapter($directory)),
            str_replace('\\', '/', $location),
        ];
    }

    /**
     * @return array{FilesystemOperator, string}
     */
    private static function filesystemForPath(string $path): array
    {
        [$mountedFilesystem, $mountedLocation] = self::resolveMountedFilesystem($path);
        if ($mountedFilesystem !== null) {
            return [$mountedFilesystem, ltrim($mountedLocation, '/')];
        }

        if (self::$defaultFilesystem !== null && !PathHelper::isAbsolute($path)) {
            return [self::$defaultFilesystem, ltrim(str_replace('\\', '/', $path), '/')];
        }

        $normalized = PathHelper::normalize($path);
        $directory = dirname($normalized);
        $location = basename($normalized);

        return [
            new Filesystem(new LocalFilesystemAdapter($directory)),
            str_replace('\\', '/', $location),
        ];
    }

    private static function normalizeMountName(string $name): string
    {
        return strtolower(trim($name, " \t\n\r\0\x0B:/"));
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeStorageAttributes(StorageAttributes $item): array
    {
        $normalized = [
            'path' => str_replace('\\', '/', $item->path()),
            'type' => $item->type(),
        ];

        if ($item instanceof FileAttributes) {
            $normalized['file_size'] = $item->fileSize();
            $normalized['mime_type'] = $item->mimeType();
        }

        if ($item instanceof DirectoryAttributes) {
            $normalized['file_size'] = 0;
        }

        try {
            $normalized['last_modified'] = $item->lastModified();
        } catch (\Throwable) {
            $normalized['last_modified'] = null;
        }

        try {
            $normalized['visibility'] = $item->visibility();
        } catch (\Throwable) {
            $normalized['visibility'] = null;
        }

        foreach ($item->extraMetadata() as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array{?FilesystemOperator, string}
     */
    private static function resolveMountedFilesystem(string $path): array
    {
        if (preg_match('/^([a-zA-Z0-9._-]+):\/\/(.*)$/', $path, $matches) !== 1) {
            return [null, ''];
        }

        $mount = self::normalizeMountName($matches[1]);
        $location = $matches[2];
        if (!isset(self::$mounts[$mount])) {
            throw new \RuntimeException("No Flysystem mount registered for '{$mount}'.");
        }

        return [self::$mounts[$mount], str_replace('\\', '/', $location)];
    }
}
