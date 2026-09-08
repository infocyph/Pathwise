<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\DirectoryManager\Concerns;

use FilesystemIterator;
use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\DirectoryOperationException;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Security\ZipArchiveExtractor;
use Infocyph\Pathwise\Security\ZipArchiveManifestEntry;
use Infocyph\Pathwise\Security\ZipEntryValidator;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/** @phpstan-type RemoteZipEntry array{source: string, target: string, directory: bool} */
trait DirectoryOperationsZipConcern
{
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

    private function addContentsToZip(ZipArchive $zip, string $zipPath): ?string
    {
        if ($this->isLocalPath($this->path) && is_dir($this->path)) {
            $this->addLocalContentsToZip($zip, $zipPath);

            return null;
        }

        return $this->addFlysystemContentsToZip($zip);
    }

    private function addFlysystemContentsToZip(ZipArchive $zip): string
    {
        $stagingDirectory = PathHelper::createTempDirectory('pathwise_zip_stage_');
        if (!is_string($stagingDirectory)) {
            throw new DirectoryOperationException('Unable to create ZIP staging directory.');
        }

        $sourceLocation = $this->storageLocation($this->path);

        try {
            foreach ($this->listStorageEntries($this->path, true) as $item) {
                $relative = $this->relativeStoragePath($sourceLocation, $this->entryPath($item));
                if ($relative === '') {
                    continue;
                }

                if ($this->zipDestination === $this->buildPath($this->path, $relative)) {
                    continue;
                }

                $zipPathName = str_replace('\\', '/', $relative);
                if ($this->entryType($item) === 'dir') {
                    $this->assertDirectoryZipMutation(
                        $zip->addEmptyDir(rtrim($zipPathName, '/')),
                        "add ZIP directory: {$zipPathName}",
                    );

                    continue;
                }

                $stagedPath = PathHelper::join($stagingDirectory, $relative);
                $parent = dirname($stagedPath);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new DirectoryOperationException("Unable to create ZIP staging directory: {$parent}");
                }

                FlysystemHelper::copy($this->buildPath($this->path, $relative), $stagedPath);
                $this->assertDirectoryZipMutation(
                    $zip->addFile($stagedPath, $zipPathName),
                    "add ZIP entry: {$zipPathName}",
                );
            }
        } catch (\Throwable $exception) {
            PathHelper::deleteDirectory($stagingDirectory);

            throw $exception;
        }

        return $stagingDirectory;
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
            if ($file->isLink()) {
                throw new DirectoryOperationException(
                    "Symbolic links are not followed during ZIP creation: {$file->getPathname()}",
                );
            }

            $currentPath = PathHelper::normalize($file->getPathname());
            if ($currentPath === $normalizedZipPath) {
                continue;
            }

            $subPathName = ltrim(str_replace('\\', '/', substr($currentPath, strlen($normalizedSourcePath))), '/');
            if ($file->isDir()) {
                $this->assertDirectoryZipMutation($zip->addEmptyDir($subPathName), "add ZIP directory: {$subPathName}");

                continue;
            }

            $this->assertDirectoryZipMutation(
                $zip->addFile($file->getPathname(), $subPathName),
                "add ZIP entry: {$subPathName}",
            );
        }
    }

    private function assertDirectoryZipMutation(bool $succeeded, string $operation): void
    {
        if (!$succeeded) {
            throw new DirectoryOperationException("Unable to {$operation}.");
        }
    }

    private function assertRemoteZipTarget(string $target, bool $directory): void
    {
        if ($directory) {
            if (FlysystemHelper::fileExists($target)) {
                throw new DirectoryOperationException("ZIP directory conflicts with an existing file: {$target}");
            }

            return;
        }

        if (FlysystemHelper::fileExists($target) || FlysystemHelper::directoryExists($target)) {
            throw new DirectoryOperationException("Remote ZIP extraction refuses to overwrite an existing path: {$target}");
        }
    }

    /** @return list<RemoteZipEntry> */
    private function collectRemoteZipEntries(string $stage): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen(rtrim($stage, '/\\')))), '/');
            if ($relative === '') {
                continue;
            }

            $target = $this->buildPath($this->path, $relative);
            $directory = $item->isDir();
            $this->assertRemoteZipTarget($target, $directory);
            $entries[] = [
                'source' => $item->getPathname(),
                'target' => $target,
                'directory' => $directory,
            ];
        }

        return $entries;
    }

    private function copyZipStageToStorage(string $stage): void
    {
        $this->publishRemoteZipEntries($this->collectRemoteZipEntries($stage));
    }

    /** @param array<int, ZipArchiveManifestEntry> $validatedEntries */
    private function extractZipContents(string $localSource, string $source, array $validatedEntries): void
    {
        $zip = new ZipArchive();
        if ($zip->open($localSource) !== true) {
            throw new DirectoryOperationException("Unable to open ZIP source: {$source}");
        }

        $stage = null;

        try {
            if ($this->isLocalPath($this->path)) {
                ZipArchiveExtractor::extractToLocal($zip, $validatedEntries, $this->path);

                return;
            }

            $stage = PathHelper::createTempDirectory('pathwise_unzip_stage_');
            if (!is_string($stage)) {
                throw new DirectoryOperationException('Unable to create secure ZIP extraction staging directory.');
            }

            ZipArchiveExtractor::extractToLocal($zip, $validatedEntries, $stage);
            $this->copyZipStageToStorage($stage);
        } finally {
            $zip->close();
            if (is_string($stage)) {
                PathHelper::deleteDirectory($stage);
            }
        }
    }

    private function openZipArchive(string $zipPath, string $destination, bool $useLocalDestination): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
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

    /** @return array{string, bool} */
    private function prepareLocalZipSource(string $source): array
    {
        if ($this->isLocalPath($source) && is_file($source)) {
            return [$source, false];
        }

        $tempSource = tempnam(sys_get_temp_dir(), 'pathwise_unzip_');
        if ($tempSource === false) {
            throw new DirectoryOperationException('Unable to create temporary ZIP source.');
        }

        try {
            FlysystemHelper::copy($source, $tempSource);
        } catch (\Throwable) {
            $this->unlinkFileSilently($tempSource);

            throw new DirectoryOperationException("Unable to read ZIP source: {$source}");
        }

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

    /** @param list<RemoteZipEntry> $entries */
    private function publishRemoteZipEntries(array $entries): void
    {
        $createdFiles = [];
        $createdDirectories = [];

        try {
            foreach ($entries as $entry) {
                $this->publishRemoteZipEntry($entry, $createdFiles, $createdDirectories);
            }
        } catch (\Throwable $exception) {
            $this->rollbackRemoteZipEntries($createdFiles, $createdDirectories);

            throw $exception;
        }
    }

    /**
     * @param RemoteZipEntry $entry
     * @param list<string> $createdFiles
     * @param list<string> $createdDirectories
     */
    private function publishRemoteZipEntry(array $entry, array &$createdFiles, array &$createdDirectories): void
    {
        if ($entry['directory']) {
            if (!FlysystemHelper::directoryExists($entry['target'])) {
                FlysystemHelper::createDirectory($entry['target']);
                $createdDirectories[] = $entry['target'];
            }

            return;
        }

        $stream = fopen($entry['source'], 'rb');
        if (!is_resource($stream)) {
            throw new DirectoryOperationException("Unable to read staged ZIP entry: {$entry['source']}");
        }

        try {
            FlysystemHelper::writeStream($entry['target'], $stream);
            $createdFiles[] = $entry['target'];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param list<string> $createdFiles
     * @param list<string> $createdDirectories
     */
    private function rollbackRemoteZipEntries(array $createdFiles, array $createdDirectories): void
    {
        for ($index = count($createdFiles) - 1; $index >= 0; $index--) {
            if (FlysystemHelper::fileExists($createdFiles[$index])) {
                FlysystemHelper::delete($createdFiles[$index]);
            }
        }
        for ($index = count($createdDirectories) - 1; $index >= 0; $index--) {
            if (FlysystemHelper::directoryExists($createdDirectories[$index])) {
                FlysystemHelper::deleteDirectory($createdDirectories[$index]);
            }
        }
    }

    private function tryNativeUnzip(string $localSource, string $source): bool
    {
        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new NativeExecutionException(
                "Native unzip of {$source} from {$localSource} is unavailable because hardened extraction requires Pathwise byte and rollback enforcement.",
            );
        }

        return false;
    }

    private function tryNativeZip(string $destination, bool $useLocalDestination): bool
    {
        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            if (
                !$this->isLocalPath($this->path)
                || !$useLocalDestination
                || FlysystemHelper::isSameOrDescendant($this->path, $destination)
            ) {
                throw new UnsupportedStorageOperationException('Native zip requires local source and destination paths.');
            }
            if (!NativeOperationsAdapter::canUseNativeZipCompression()) {
                throw new NativeExecutionException('Native ZIP compression executables are unavailable.');
            }
        }

        if (
            $this->executionStrategy === ExecutionStrategy::PHP
            || !NativeOperationsAdapter::canUseNativeZipCompression()
            || !$this->isLocalPath($this->path)
            || !$useLocalDestination
            || FlysystemHelper::isSameOrDescendant($this->path, $destination)
        ) {
            return false;
        }

        $native = NativeOperationsAdapter::compressToZip($this->path, $destination);
        if ($native->success) {
            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new NativeExecutionException(
                "Native zip failed with exit code {$native->exitCode}: " . implode("\n", $native->output),
                $native,
            );
        }

        return false;
    }

    /** @return array<int, ZipArchiveManifestEntry> */
    private function validateZipEntries(string $localSource, string $source): array
    {
        $zip = new ZipArchive();
        if ($zip->open($localSource) !== true) {
            throw new DirectoryOperationException("Unable to open ZIP source: {$source}");
        }

        try {
            return ZipEntryValidator::validateArchive(
                $zip,
                $this->path,
                $this->maxEntries,
                $this->maxEntryUncompressedBytes,
                $this->maxTotalUncompressedBytes,
                $this->maxCompressionRatio,
            );
        } finally {
            $zip->close();
        }
    }
}
