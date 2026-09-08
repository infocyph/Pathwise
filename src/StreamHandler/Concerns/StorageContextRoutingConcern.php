<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Concerns;

use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\MetadataHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\FilesystemOperator;

/**
 * Route processor storage I/O through an optional instance-scoped context.
 *
 * Direct absolute filesystem paths remain local. With a context configured,
 * relative paths use its default filesystem and scheme paths must name one of
 * its configured filesystems. Without a context, the existing FlysystemHelper
 * routing remains available for low-level and standalone use.
 */
trait StorageContextRoutingConcern
{
    private ?StorageContext $storageContext = null;

    public function setStorageContext(?StorageContext $storageContext): void
    {
        $this->storageContext = $storageContext;
    }

    private function storageChecksum(string $path, string $algorithm = 'sha256'): ?string
    {
        if (!in_array($algorithm, hash_algos(), true) || !$this->storageFileExists($path)) {
            return null;
        }

        $resolved = $this->storageResolution($path);
        if ($resolved === null) {
            return FlysystemHelper::checksum($path, $algorithm);
        }

        [$filesystem, $location] = $resolved;

        return $filesystem->checksum($location, ['checksum_algo' => $algorithm]);
    }

    private function storageCopy(string $source, string $destination): void
    {
        $sourceResolution = $this->storageResolution($source);
        $destinationResolution = $this->storageResolution($destination);

        if ($sourceResolution === null && $destinationResolution === null) {
            FlysystemHelper::copy($source, $destination);

            return;
        }

        if (
            $sourceResolution !== null
            && $destinationResolution !== null
            && $sourceResolution[0] === $destinationResolution[0]
        ) {
            $sourceResolution[0]->copy($sourceResolution[1], $destinationResolution[1]);

            return;
        }

        $stream = $this->storageReadStream($source);
        if (!is_resource($stream)) {
            throw new \RuntimeException("Unable to read source stream: {$source}");
        }

        try {
            $this->storageWriteStream($destination, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function storageCreateDirectory(string $path): void
    {
        $resolved = $this->storageResolution($path);
        if ($resolved === null) {
            FlysystemHelper::createDirectory($path);

            return;
        }

        $resolved[0]->createDirectory($resolved[1]);
    }

    private function storageDelete(string $path): void
    {
        $resolved = $this->storageResolution($path);
        if ($resolved === null) {
            FlysystemHelper::delete($path);

            return;
        }

        $resolved[0]->delete($resolved[1]);
    }

    private function storageDeleteDirectory(string $path): void
    {
        $resolved = $this->storageResolution($path);
        if ($resolved === null) {
            FlysystemHelper::deleteDirectory($path);

            return;
        }

        $resolved[0]->deleteDirectory($resolved[1]);
    }

    private function storageDirectLocalPath(string $path): ?string
    {
        if ($this->storageUsesContext($path)) {
            try {
                return $this->storageContext?->localPath($path);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return FlysystemHelper::isLocalPath($path) ? PathHelper::normalize($path) : null;
    }

    private function storageDirectoryExists(string $path): bool
    {
        $resolved = $this->storageResolution($path);

        return $resolved === null
            ? FlysystemHelper::directoryExists($path)
            : $resolved[0]->directoryExists($resolved[1]);
    }

    private function storageFileExists(string $path): bool
    {
        $resolved = $this->storageResolution($path);

        return $resolved === null
            ? FlysystemHelper::fileExists($path)
            : $resolved[0]->fileExists($resolved[1]);
    }

    private function storageIsLocalPath(string $path): bool
    {
        return $this->storageDirectLocalPath($path) !== null;
    }

    private function storageIsSameOrDescendant(string $root, string $path): bool
    {
        $rootUsesContext = $this->storageUsesContext($root);
        $pathUsesContext = $this->storageUsesContext($path);
        if ($rootUsesContext !== $pathUsesContext) {
            return false;
        }

        if (!$rootUsesContext) {
            return FlysystemHelper::isSameOrDescendant($root, $path);
        }

        $rootResolution = $this->storageContext?->resolve($root);
        $pathResolution = $this->storageContext?->resolve($path);
        if ($rootResolution === null || $pathResolution === null || $rootResolution[0] !== $pathResolution[0]) {
            return false;
        }

        $rootLocation = trim(str_replace('\\', '/', $rootResolution[1]), '/');
        $pathLocation = trim(str_replace('\\', '/', $pathResolution[1]), '/');
        if ($rootLocation === '') {
            return true;
        }

        return $pathLocation === $rootLocation || str_starts_with($pathLocation, $rootLocation . '/');
    }

    private function storageLastModified(string $path): int
    {
        $resolved = $this->storageResolution($path);

        return $resolved === null
            ? FlysystemHelper::lastModified($path)
            : $resolved[0]->lastModified($resolved[1]);
    }

    private function storageMimeType(string $path): ?string
    {
        $resolved = $this->storageResolution($path);
        if ($resolved === null) {
            return MetadataHelper::getMimeType($path);
        }

        try {
            return $resolved[0]->mimeType($resolved[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function storageRead(string $path): string
    {
        $resolved = $this->storageResolution($path);

        return $resolved === null
            ? FlysystemHelper::read($path)
            : $resolved[0]->read($resolved[1]);
    }

    /** @return resource */
    private function storageReadStream(string $path): mixed
    {
        $resolved = $this->storageResolution($path);

        return $resolved === null
            ? FlysystemHelper::readStream($path)
            : $resolved[0]->readStream($resolved[1]);
    }

    private function storageSize(string $path): int
    {
        $resolved = $this->storageResolution($path);

        return $resolved === null
            ? FlysystemHelper::size($path)
            : $resolved[0]->fileSize($resolved[1]);
    }

    private function storageWrite(string $path, string $contents): void
    {
        $resolved = $this->storageResolution($path);
        if ($resolved === null) {
            FlysystemHelper::write($path, $contents);

            return;
        }

        $resolved[0]->write($resolved[1], $contents);
    }

    private function storageWriteStream(string $path, mixed $stream): void
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Storage write stream must be a resource.');
        }

        $resolved = $this->storageResolution($path);
        if ($resolved === null) {
            FlysystemHelper::writeStream($path, $stream);

            return;
        }

        $resolved[0]->writeStream($resolved[1], $stream);
    }

    /** @return array{FilesystemOperator, string}|null */
    private function storageResolution(string $path): ?array
    {
        if (!$this->storageUsesContext($path)) {
            return null;
        }

        return $this->storageContext?->resolve($path);
    }

    private function storageUsesContext(string $path): bool
    {
        return $this->storageContext !== null
            && (PathHelper::hasScheme($path) || !PathHelper::isAbsolute($path));
    }
}
