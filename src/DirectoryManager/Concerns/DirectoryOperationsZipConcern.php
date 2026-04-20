<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\DirectoryManager\Concerns;

use FilesystemIterator;
use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\DirectoryOperationException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

trait DirectoryOperationsZipConcern
{
    /**
     * Deletes all files and directories in the given local directory.
     *
     * @param string $directory The directory to delete contents of.
     * @return bool True if the directory contents were successfully deleted, false otherwise.
     */
    protected function deleteDirectoryContents(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $realPath = $file->getRealPath();
            if (!is_string($realPath) || $realPath === '') {
                continue;
            }

            if ($file->isDir()) {
                rmdir($realPath);

                continue;
            }

            unlink($realPath);
        }

        return true;
    }

    private function addContentsToZip(ZipArchive $zip, string $zipPath): void
    {
        if ($this->isLocalPath($this->path) && is_dir($this->path)) {
            $this->addLocalContentsToZip($zip, $zipPath);

            return;
        }

        $this->addFlysystemContentsToZip($zip);
    }

    private function addFlysystemContentsToZip(ZipArchive $zip): void
    {
        $sourceLocation = $this->storageLocation($this->path);
        foreach ($this->listStorageEntries($this->path, true) as $item) {
            $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
            if ($relative === '') {
                continue;
            }

            $zipPathName = str_replace('\\', '/', $relative);
            if ($this->entryType($item) === 'dir') {
                $zip->addEmptyDir(rtrim($zipPathName, '/'));

                continue;
            }

            $zip->addFromString($zipPathName, FlysystemHelper::read($this->buildPath($this->path, $relative)));
        }
    }

    private function addLocalContentsToZip(ZipArchive $zip, string $zipPath): void
    {
        $normalizedZipPath = PathHelper::normalize($zipPath);
        $normalizedSourcePath = rtrim(PathHelper::normalize($this->path), '/');
        $directoryIterator = new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $currentPath = PathHelper::normalize($file->getPathname());
            if ($currentPath === $normalizedZipPath) {
                continue;
            }

            $subPathName = ltrim(str_replace('\\', '/', substr($currentPath, strlen($normalizedSourcePath))), '/');
            if ($file->isDir()) {
                $zip->addEmptyDir($subPathName);

                continue;
            }

            $zip->addFile($file->getPathname(), $subPathName);
        }
    }

    private function ensureZipEntryDirectory(string $entry): void
    {
        $relativeDir = pathinfo($entry, PATHINFO_DIRNAME);
        if ($relativeDir === '' || $relativeDir === '.') {
            return;
        }

        $targetDir = $this->buildPath($this->path, str_replace('\\', '/', $relativeDir));
        if (!FlysystemHelper::directoryExists($targetDir)) {
            FlysystemHelper::createDirectory($targetDir);
        }
    }

    private function extractSingleZipEntry(ZipArchive $zip, int $index): void
    {
        $entry = $this->sanitizeZipEntryPath((string) $zip->getNameIndex($index));
        if ($entry === '') {
            return;
        }

        if (str_ends_with((string) $entry, '/')) {
            FlysystemHelper::createDirectory($this->buildPath($this->path, rtrim((string) $entry, '/')));

            return;
        }

        $this->ensureZipEntryDirectory($entry);
        $contents = $zip->getFromIndex($index);
        if (!is_string($contents)) {
            throw new DirectoryOperationException("Unable to extract ZIP entry: {$entry}");
        }

        FlysystemHelper::write($this->buildPath($this->path, $entry), $contents);
    }

    private function extractZipContents(string $localSource, string $source): void
    {
        $zip = new ZipArchive();
        if ($zip->open($localSource) !== true) {
            throw new DirectoryOperationException("Unable to open ZIP source: {$source}");
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $this->extractSingleZipEntry($zip, $i);
            }
        } finally {
            $zip->close();
        }
    }

    private function openZipArchive(string $zipPath, string $destination, bool $useLocalDestination): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
            return $zip;
        }

        if (!$useLocalDestination && is_file($zipPath)) {
            $this->unlinkFileSilently($zipPath);
        }

        throw new DirectoryOperationException("Unable to create ZIP archive at '{$destination}'.");
    }

    private function persistZipToDestination(string $zipPath, string $destination): void
    {
        $stream = fopen($zipPath, 'rb');
        if (!is_resource($stream)) {
            if (is_file($zipPath)) {
                $this->unlinkFileSilently($zipPath);
            }

            throw new DirectoryOperationException("Unable to stream ZIP archive at '{$zipPath}'.");
        }

        try {
            FlysystemHelper::writeStream($destination, $stream);
        } finally {
            fclose($stream);
            if (is_file($zipPath)) {
                $this->unlinkFileSilently($zipPath);
            }
        }
    }

    /**
     * @return array{string, bool}
     */
    private function prepareLocalZipSource(string $source): array
    {
        if ($this->isLocalPath($source) && is_file($source)) {
            return [$source, false];
        }

        $tempSource = tempnam(sys_get_temp_dir(), 'pathwise_unzip_');
        if ($tempSource === false) {
            throw new DirectoryOperationException('Unable to create temporary ZIP source.');
        }

        $sourceStream = FlysystemHelper::readStream($source);
        $targetStream = fopen($tempSource, 'wb');
        if (!is_resource($sourceStream) || !is_resource($targetStream)) {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
            if (is_resource($targetStream)) {
                fclose($targetStream);
            }
            $this->unlinkFileSilently($tempSource);

            throw new DirectoryOperationException("Unable to read ZIP source: {$source}");
        }

        stream_copy_to_stream($sourceStream, $targetStream);
        fclose($sourceStream);
        fclose($targetStream);

        return [$tempSource, true];
    }

    private function prepareZipPath(string $destination, bool $useLocalDestination): string
    {
        if (!$useLocalDestination) {
            $tempZip = tempnam(sys_get_temp_dir(), 'pathwise_zip_');
            if ($tempZip === false) {
                throw new DirectoryOperationException('Unable to allocate temporary ZIP path.');
            }

            $this->unlinkFileSilently($tempZip);

            return $tempZip;
        }

        $parent = dirname($destination);
        if (!is_dir($parent)) {
            $this->createDirectorySilently($parent);
        }

        return $destination;
    }

    private function sanitizeZipEntryPath(string $entry): string
    {
        $normalized = str_replace('\\', '/', $entry);
        $trimmed = ltrim($normalized, '/');
        if ($trimmed === '') {
            return '';
        }

        $safePath = preg_replace('#/+#', '/', $trimmed) ?? '';
        $safePath = preg_replace('#(^|/)\./#', '$1', $safePath) ?? $safePath;
        $trimmedSafePath = rtrim($safePath, '/');

        if (
            str_contains($trimmedSafePath, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $trimmedSafePath) === 1
            || preg_match('/^[A-Za-z]:($|\/)/', $trimmedSafePath) === 1
        ) {
            throw new DirectoryOperationException("Unsafe ZIP entry path detected: {$entry}");
        }

        if ($trimmedSafePath === '') {
            return '';
        }

        return str_ends_with($normalized, '/') ? $trimmedSafePath . '/' : $trimmedSafePath;
    }

    private function tryNativeUnzip(string $localSource, string $source): bool
    {
        if (
            $this->executionStrategy === ExecutionStrategy::PHP
            || !NativeOperationsAdapter::canUseNativeCompression()
            || !$this->isLocalPath($this->path)
        ) {
            return false;
        }

        $native = NativeOperationsAdapter::decompressZip($localSource, $this->path);
        if ($native['success']) {
            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new DirectoryOperationException("Native unzip failed for '{$source}' to '{$this->path}'.");
        }

        return false;
    }

    private function tryNativeZip(string $destination, bool $useLocalDestination): bool
    {
        if (
            $this->executionStrategy === ExecutionStrategy::PHP
            || !NativeOperationsAdapter::canUseNativeCompression()
            || !$this->isLocalPath($this->path)
            || !$useLocalDestination
        ) {
            return false;
        }

        $native = NativeOperationsAdapter::compressToZip($this->path, $destination);
        if ($native['success']) {
            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new DirectoryOperationException("Native zip failed for '{$this->path}' to '{$destination}'.");
        }

        return false;
    }
}
