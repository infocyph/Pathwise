<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Concerns;

use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\FilesystemOperator;

/**
 * Route processor storage I/O through an optional instance-scoped context.
 *
 * Absolute paths remain direct-local. With a context configured, relative paths
 * use its default filesystem and scheme paths must name one of its filesystems.
 * Without a context, existing FlysystemHelper routing remains available for
 * low-level/standalone use.
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
        if ($this->storageContext === null || PathHelper::isAbsolute($path)) {
            return FlysystemHelper::isLocalPath($path);
        }

        $canonical = $this->storageContext->path($path);
        $separator = strpos($canonical, '://');
        if ($separator === false) {
            return false;
        }

        return $this->storageContext->isLocal(substr($canonical, 0, $separator));
    }

    private function storageIsSameOrDescendant(string $root, string $path): bool
    {
        return FlysystemHelper::isSameOrDescendant(
            $this->storageComparablePath($root),
            $this->storageComparablePath($path),
        );
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
            return \Infocyph\Pathwise\Utils\MetadataHelper::getMimeType($path);
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

    private function storageComparablePath(string $path): string
    {
        if ($this->storageContext === null || PathHelper::isAbsolute($path)) {
            return PathHelper::normalize($path);
        }

        return $this->storageContext->path($path);
    }

    /** @return array{FilesystemOperator, string}|null */
    private function storageResolution(string $path): ?array
    {
        if ($this->storageContext === null || PathHelper::isAbsolute($path)) {
            return null;
        }

        return $this->storageContext->resolve($path);
    }
}
