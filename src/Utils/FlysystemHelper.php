<?php

namespace Infocyph\Pathwise\Utils;

use DateTimeInterface;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;

final class FlysystemHelper
{
    private static ?FilesystemOperator $defaultFilesystem = null;
    /** @var array<string, FilesystemOperator> */
    private static array $mounts = [];

    public static function checksum(string $path, string $algorithm = 'sha256', array $config = []): ?string
    {
        if (!in_array($algorithm, hash_algos(), true) || !self::fileExists($path)) {
            return null;
        }

        [$filesystem, $location] = self::filesystemForFile($path);

        $config['checksum_algo'] = $algorithm;

        return $filesystem->checksum($location, $config);
    }

    public static function clearDefaultFilesystem(): void
    {
        self::$defaultFilesystem = null;
    }

    public static function clearMounts(): void
    {
        self::$mounts = [];
    }

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

    public static function createDirectory(string $path, array $config = []): void
    {
        [$filesystem, $location] = self::filesystemForDirectory($path);
        $filesystem->createDirectory($location, $config);
    }

    public static function delete(string $path): void
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        $filesystem->delete($location);
    }

    public static function deleteDirectory(string $path): void
    {
        [$filesystem, $location] = self::filesystemForDirectory($path);
        $filesystem->deleteDirectory($location);
    }

    public static function directoryExists(string $path): bool
    {
        try {
            [$filesystem, $location] = self::filesystemForDirectory($path);

            return $filesystem->directoryExists($location);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function fileExists(string $path): bool
    {
        try {
            [$filesystem, $location] = self::filesystemForFile($path);

            return $filesystem->fileExists($location);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function has(string $path): bool
    {
        try {
            [$filesystem, $location] = self::filesystemForPath($path);

            return $filesystem->has($location);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasDefaultFilesystem(): bool
    {
        return self::$defaultFilesystem !== null;
    }

    public static function lastModified(string $path): int
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->lastModified($location);
    }

    /**
     * @return array<int, mixed>
     */
    public static function listContents(string $path, bool $deep = true): array
    {
        $items = [];
        foreach (self::listContentsListing($path, $deep) as $item) {
            if (!$item instanceof StorageAttributes) {
                continue;
            }

            $items[] = self::normalizeStorageAttributes($item);
        }

        return $items;
    }

    public static function listContentsListing(string $path, bool $deep = true): DirectoryListing
    {
        [$filesystem, $location] = self::filesystemForDirectory($path);

        return $filesystem->listContents($location, $deep);
    }

    public static function mimeType(string $path): ?string
    {
        if (!self::fileExists($path)) {
            return null;
        }

        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->mimeType($location);
    }

    public static function mount(string $name, FilesystemOperator $filesystem): void
    {
        $normalized = self::normalizeMountName($name);
        self::$mounts[$normalized] = $filesystem;
    }

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

    public static function moveDirectory(string $source, string $destination, array $config = []): void
    {
        self::copyDirectory($source, $destination, $config);
        self::deleteDirectory($source);
    }

    public static function publicUrl(string $path, array $config = []): string
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        if (!method_exists($filesystem, 'publicUrl')) {
            throw new \RuntimeException('Public URL generation is not supported by the resolved filesystem.');
        }

        /** @var callable $callable */
        $callable = $filesystem->publicUrl(...);

        return $callable($location, $config);
    }

    public static function read(string $path): string
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->read($location);
    }

    public static function readStream(string $path): mixed
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->readStream($location);
    }

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

    public static function setDefaultFilesystem(FilesystemOperator $filesystem): void
    {
        self::$defaultFilesystem = $filesystem;
    }

    public static function setVisibility(string $path, string $visibility): void
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        $filesystem->setVisibility($location, $visibility);
    }

    public static function size(string $path): int
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->fileSize($location);
    }

    public static function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        if (!method_exists($filesystem, 'temporaryUrl')) {
            throw new \RuntimeException('Temporary URL generation is not supported by the resolved filesystem.');
        }

        /** @var callable $callable */
        $callable = $filesystem->temporaryUrl(...);

        return $callable($location, $expiresAt, $config);
    }

    public static function unmount(string $name): void
    {
        $normalized = self::normalizeMountName($name);
        unset(self::$mounts[$normalized]);
    }

    public static function visibility(string $path): ?string
    {
        [$filesystem, $location] = self::filesystemForFile($path);

        return $filesystem->visibility($location);
    }

    public static function write(string $path, string $contents, array $config = []): void
    {
        [$filesystem, $location] = self::filesystemForFile($path);
        $filesystem->write($location, $contents, $config);
    }

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

        return array_merge($normalized, $item->extraMetadata());
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
