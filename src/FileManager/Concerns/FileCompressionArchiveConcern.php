<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager\Concerns;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Security\ZipArchiveExtractor;
use Infocyph\Pathwise\Security\ZipArchiveManifestEntry;
use Infocyph\Pathwise\Security\ZipEntryValidator;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use ZipArchive;

/**
 * @phpstan-type ExtractionDestination array{
 *     extractDestination: string,
 *     extractTempDir: string|null,
 *     isRemote: bool
 * }
 * @phpstan-type RemoteExtractionEntry array{source: string, target: string, directory: bool}
 */
trait FileCompressionArchiveConcern
{
    private function addArchiveEntry(ZipArchive $zip, string $sourcePath, string $relativePath): void
    {
        $this->triggerHook('beforeAdd', $sourcePath, $relativePath);
        if ($this->password !== null) {
            $this->assertZipMutation($zip->setPassword($this->password), 'set the ZIP password');
            $this->assertZipMutation($zip->addFile($sourcePath, $relativePath), "add ZIP entry: {$relativePath}");
            $this->assertZipMutation(
                $zip->setEncryptionName($relativePath, $this->encryptionAlgorithm),
                "encrypt ZIP entry: {$relativePath}",
            );

            $this->triggerHook('afterAdd', $sourcePath, $relativePath);

            return;
        }

        $this->assertZipMutation($zip->addFile($sourcePath, $relativePath), "add ZIP entry: {$relativePath}");
        $this->triggerHook('afterAdd', $sourcePath, $relativePath);
    }

    private function addDirectoryEntriesToZip(string $path, ZipArchive $zip, string $baseDir): void
    {
        $relativePath = $this->getRelativePath($path, $baseDir);
        if ($relativePath !== '' && !$this->shouldTraverseDirectory($relativePath)) {
            return;
        }

        if ($relativePath !== '') {
            $this->assertZipMutation($zip->addEmptyDir($relativePath), "add ZIP directory: {$relativePath}");
        }

        $entries = scandir($path);
        if ($entries === false) {
            throw new CompressionException("Failed to read directory: {$path}");
        }

        foreach ($entries as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $this->addFilesToZip($path . DIRECTORY_SEPARATOR . $file, $zip, $baseDir);
        }
    }

    private function addFilesToZip(string $path, ZipArchive $zip, ?string $baseDir = null): void
    {
        $baseDir ??= $path;
        if (is_link($path)) {
            throw new CompressionException("Symbolic links are not followed during ZIP creation: {$path}");
        }

        if (is_dir($path)) {
            $this->addDirectoryEntriesToZip($path, $zip, $baseDir);

            return;
        }

        $this->addSinglePathToZip($path, $zip, $baseDir);
    }

    /** @param list<string> $extensions */
    private function addFilesToZipWithFilter(string $path, ZipArchive $zip, ?string $relativePath, array $extensions): void
    {
        $relativePath ??= basename($path);
        $relativePath = $this->normalizeZipPath($relativePath);
        if (is_link($path)) {
            throw new CompressionException("Symbolic links are not followed during ZIP creation: {$path}");
        }

        if (is_dir($path)) {
            $this->addFilteredDirectoryToZip($path, $zip, $relativePath, $extensions);

            return;
        }
        if ($extensions !== [] && !in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true)) {
            return;
        }
        if (!$this->shouldIncludePath($relativePath)) {
            return;
        }

        $this->addArchiveEntry($zip, $path, $relativePath);
        $this->advanceProgress('compress', $relativePath);
    }

    private function addFileToArchive(string $filePath, string $zipPath): void
    {
        if (!PathHelper::hasScheme($filePath) && is_link($filePath)) {
            throw new CompressionException("Symbolic links are not followed during ZIP creation: {$filePath}");
        }

        $this->triggerHook('beforeAdd', $filePath, $zipPath);
        if ($this->password !== null) {
            $this->assertZipMutation($this->zip->setPassword($this->password), 'set the ZIP password');
        }

        $cleanupPath = null;
        $localFilePath = $this->isLocalFilesystemPath($filePath)
            ? $filePath
            : $this->localizeCompressionSource($filePath, $cleanupPath);
        $this->deferLocalizedCleanupPath($cleanupPath);
        $added = $this->zip->addFile($localFilePath, $zipPath);

        if (!$added) {
            throw new CompressionException("Failed to add file to ZIP: {$filePath}");
        }

        if ($this->password !== null) {
            $this->assertZipMutation(
                $this->zip->setEncryptionName($zipPath, $this->encryptionAlgorithm),
                "encrypt ZIP entry: {$zipPath}",
            );
        }
        $this->triggerHook('afterAdd', $filePath, $zipPath);
    }

    /** @param list<string> $extensions */
    private function addFilteredDirectoryToZip(
        string $path,
        ZipArchive $zip,
        string $relativePath,
        array $extensions,
    ): void {
        if ($relativePath !== '' && !$this->shouldTraverseDirectory($relativePath)) {
            return;
        }

        $this->assertZipMutation($zip->addEmptyDir($relativePath), "add ZIP directory: {$relativePath}");
        $entries = scandir($path);
        if ($entries === false) {
            throw new CompressionException("Failed to read directory: {$path}");
        }

        foreach ($entries as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $this->addFilesToZipWithFilter(
                $path . DIRECTORY_SEPARATOR . $file,
                $zip,
                "{$relativePath}/{$file}",
                $extensions,
            );
        }
    }

    private function addSinglePathToZip(string $path, ZipArchive $zip, string $baseDir): void
    {
        $relativePath = $this->getRelativePath($path, $baseDir);
        if ($relativePath === '') {
            $relativePath = basename($path);
        }

        if (!$this->shouldIncludePath($relativePath)) {
            return;
        }

        $this->addArchiveEntry($zip, $path, $relativePath);
        $this->advanceProgress('compress', $relativePath);
    }

    private function advanceProgress(string $operation, string $path): void
    {
        if (!is_callable($this->progressCallback)) {
            return;
        }

        $this->progressCurrent++;
        ($this->progressCallback)([
            'operation' => $operation,
            'path' => $path,
            'current' => $this->progressCurrent,
            'total' => $this->progressTotal,
        ]);
    }

    private function applyArchivePassword(): void
    {
        if ($this->password !== null) {
            $this->assertZipMutation($this->zip->setPassword($this->password), 'set the ZIP password');
        }
    }

    private function assertRemoteExtractionTarget(string $target, bool $directory): void
    {
        if ($directory) {
            if (FlysystemHelper::fileExists($target)) {
                throw new CompressionException("Remote ZIP directory conflicts with an existing file: {$target}");
            }

            return;
        }

        if (FlysystemHelper::fileExists($target) || FlysystemHelper::directoryExists($target)) {
            throw new CompressionException("Remote ZIP extraction refuses to overwrite an existing path: {$target}");
        }
    }

    private function assertZipMutation(bool $succeeded, string $operation): void
    {
        if (!$succeeded) {
            throw new CompressionException("Unable to {$operation}.");
        }
    }

    private function attemptNativeDecompression(string $destination, bool $isRemoteDestination): bool
    {
        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            $destinationType = $isRemoteDestination ? 'remote' : 'local';

            throw new NativeExecutionException(
                "Native {$destinationType} decompression to {$destination} is unavailable because hardened extraction requires Pathwise byte and rollback enforcement.",
            );
        }

        return false;
    }

    /** @return list<RemoteExtractionEntry> */
    private function collectRemoteExtractionEntries(string $localSource, string $destination): array
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localSource, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relative = $this->getRelativePath($item->getPathname(), $localSource);
            if ($relative === '') {
                continue;
            }

            $target = PathHelper::join($destination, $relative);
            $directory = $item->isDir();
            $this->assertRemoteExtractionTarget($target, $directory);
            $entries[] = [
                'source' => $item->getPathname(),
                'target' => $target,
                'directory' => $directory,
            ];
        }

        return $entries;
    }

    private function copyLocalDirectoryToFlysystem(string $localSource, string $destination): void
    {
        $this->publishRemoteExtractionEntries($this->collectRemoteExtractionEntries($localSource, $destination));
    }

    /** @param list<string> $extensions */
    private function countFilesForCompression(string $source, array $extensions = []): int
    {
        if (is_file($source)) {
            $relative = basename($source);
            if (!$this->matchesExtensions($source, $extensions)) {
                return 0;
            }

            return $this->shouldIncludePath($relative) ? 1 : 0;
        }

        if (!is_dir($source)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->isDir()) {
                continue;
            }

            $relative = $this->getRelativePath($item->getPathname(), $source);
            if (!$this->matchesExtensions($item->getPathname(), $extensions)) {
                continue;
            }
            if ($this->shouldIncludePath($relative)) {
                $count++;
            }
        }

        return $count;
    }

    private function createExtractionTempDirectory(): string
    {
        $extractTempDir = PathHelper::createTempDirectory('pathwise_extract_');
        if (!is_string($extractTempDir)) {
            throw new CompressionException('Unable to create extraction directory.');
        }

        return $extractTempDir;
    }

    private function emitDecompressionProgress(): void
    {
        if (!is_callable($this->progressCallback)) {
            return;
        }

        $total = $this->zip->numFiles;
        for ($i = 0; $i < $total; $i++) {
            ($this->progressCallback)([
                'operation' => 'decompress',
                'path' => (string) $this->zip->getNameIndex($i),
                'current' => $i + 1,
                'total' => $total,
            ]);
        }
    }

    /** @param array<int, ZipArchiveManifestEntry> $entries */
    private function extractArchive(
        array $entries,
        string $extractDestination,
        string $destination,
        bool $isRemoteDestination,
    ): void {
        ZipArchiveExtractor::extractToLocal($this->zip, $entries, $extractDestination);

        if ($isRemoteDestination) {
            $this->copyLocalDirectoryToFlysystem($extractDestination, $destination);
        }
    }

    private function getRelativePath(string $path, string $baseDir): string
    {
        $normalizedPath = str_replace('\\', '/', PathHelper::normalize($path));
        $normalizedBase = rtrim(str_replace('\\', '/', PathHelper::normalize($baseDir)), '/');

        if ($normalizedPath === $normalizedBase) {
            return '';
        }

        if (str_starts_with($normalizedPath, $normalizedBase . '/')) {
            return substr($normalizedPath, strlen($normalizedBase) + 1);
        }

        return ltrim($normalizedPath, '/');
    }

    /** @param list<string> $extensions */
    private function initializeProgress(string $source, array $extensions = []): void
    {
        $this->progressCurrent = 0;
        $this->progressTotal = $this->countFilesForCompression($source, $extensions);
    }

    private function isLocalFilesystemPath(string $path): bool
    {
        return FlysystemHelper::isLocalPath($path) && is_file($path);
    }

    private function isRemotePath(string $path): bool
    {
        return PathHelper::hasScheme($path) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($path));
    }

    /** @param list<string> $extensions */
    private function matchesExtensions(string $path, array $extensions): bool
    {
        if ($extensions === []) {
            return true;
        }

        return in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true);
    }

    private function normalizeZipPath(string $path): string
    {
        $hadTrailingSlash = str_ends_with(str_replace('\\', '/', $path), '/');
        $normalized = ltrim(str_replace('\\', '/', PathHelper::normalize($path)), '/');

        if ($hadTrailingSlash && $normalized !== '' && !str_ends_with($normalized, '/')) {
            $normalized .= '/';
        }

        return $normalized;
    }

    /** @return ExtractionDestination */
    private function prepareExtractionDestination(string $destination): array
    {
        $isRemoteDestination = $this->isRemotePath($destination);
        if ($isRemoteDestination) {
            $extractDestination = $this->createExtractionTempDirectory();

            return [
                'extractDestination' => $extractDestination,
                'extractTempDir' => $extractDestination,
                'isRemote' => true,
            ];
        }

        return [
            'extractDestination' => $destination,
            'extractTempDir' => null,
            'isRemote' => false,
        ];
    }

    /** @param list<RemoteExtractionEntry> $entries */
    private function publishRemoteExtractionEntries(array $entries): void
    {
        $createdFiles = [];
        $createdDirectories = [];

        try {
            foreach ($entries as $entry) {
                $this->publishRemoteExtractionEntry($entry, $createdFiles, $createdDirectories);
            }
        } catch (\Throwable $exception) {
            $this->rollbackRemoteExtraction($createdFiles, $createdDirectories);

            throw $exception;
        }
    }

    /**
     * @param RemoteExtractionEntry $entry
     * @param list<string> $createdFiles
     * @param list<string> $createdDirectories
     */
    private function publishRemoteExtractionEntry(
        array $entry,
        array &$createdFiles,
        array &$createdDirectories,
    ): void {
        if ($entry['directory']) {
            if (!FlysystemHelper::directoryExists($entry['target'])) {
                FlysystemHelper::createDirectory($entry['target']);
                $createdDirectories[] = $entry['target'];
            }

            return;
        }

        $stream = fopen($entry['source'], 'rb');
        if (!is_resource($stream)) {
            throw new CompressionException("Unable to read extracted file: {$entry['source']}");
        }

        try {
            FlysystemHelper::writeStream($entry['target'], $stream);
            $createdFiles[] = $entry['target'];
        } finally {
            fclose($stream);
        }
    }

    private function resolveDecompressionDestination(?string $destination): string
    {
        $destination ??= $this->defaultDecompressionPath;
        if (!$destination) {
            throw new CompressionException('No destination path provided for decompression.');
        }

        return PathHelper::normalize($destination);
    }

    /**
     * @param list<string> $createdFiles
     * @param list<string> $createdDirectories
     */
    private function rollbackRemoteExtraction(array $createdFiles, array $createdDirectories): void
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

    private function shouldAttemptNativeCompression(): bool
    {
        if ($this->executionStrategy === ExecutionStrategy::PHP) {
            return false;
        }

        return $this->password === null
            && $this->includePatterns === []
            && $this->excludePatterns === []
            && $this->ignorePatterns === []
            && $this->hooks === [];
    }

    /** @return array<int, ZipArchiveManifestEntry> */
    private function validateArchiveForExtraction(string $destination): array
    {
        return ZipEntryValidator::validateArchive(
            $this->zip,
            $destination,
            $this->maxEntries,
            $this->maxEntryUncompressedBytes,
            $this->maxTotalUncompressedBytes,
            $this->maxCompressionRatio,
        );
    }
}
