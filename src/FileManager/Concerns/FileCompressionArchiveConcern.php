<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager\Concerns;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use ZipArchive;

/**
 * @phpstan-type ExtractionDestination array{
 *     extractDestination: string,
 *     extractTempDir: string|null,
 *     isRemote: bool
 * }
 */
trait FileCompressionArchiveConcern
{
    private function addArchiveEntry(ZipArchive $zip, string $sourcePath, string $relativePath): void
    {
        if ($this->password !== null) {
            $zip->setPassword($this->password);
            $zip->addFile($sourcePath, $relativePath);
            $zip->setEncryptionName($relativePath, $this->encryptionAlgorithm);

            return;
        }

        $zip->addFile($sourcePath, $relativePath);
    }

    private function addDirectoryEntriesToZip(string $path, ZipArchive $zip, string $baseDir): void
    {
        $relativePath = $this->getRelativePath($path, $baseDir);
        if ($relativePath !== '' && !$this->shouldTraverseDirectory($relativePath)) {
            return;
        }

        if ($relativePath !== '') {
            $zip->addEmptyDir($relativePath);
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

    /**
     * Recursively adds files to the current ZIP archive.
     *
     * This method traverses the specified directory and adds files to the
     * ZIP archive. Directories are added as empty directories. If the
     * password is set, files are added with encryption.
     *
     * @param string $path The path to add files from.
     * @param ZipArchive $zip The ZIP archive to add files to.
     * @param string|null $baseDir The base directory to use for relative paths.
     */
    private function addFilesToZip(string $path, ZipArchive $zip, ?string $baseDir = null): void
    {
        $baseDir ??= $path;

        if (is_dir($path)) {
            $this->addDirectoryEntriesToZip($path, $zip, $baseDir);

            return;
        }

        $this->addSinglePathToZip($path, $zip, $baseDir);
    }

    /**
     * Recursively add files to the ZIP archive, filtering by extensions.
     *
     * This method traverses the specified directory and adds files to the
     * ZIP archive based on the provided file extensions. Directories are
     * added as empty directories if no matching files are found within them.
     * If a password is set, files are encrypted using the specified algorithm.
     *
     * @param string $path The path to the directory or file to add.
     * @param ZipArchive $zip The ZIP archive instance to add files to.
     * @param string|null $relativePath The relative path within the ZIP archive.
     * @param list<string> $extensions An array of file extensions to filter by.
     */
    private function addFilesToZipWithFilter(string $path, ZipArchive $zip, ?string $relativePath, array $extensions): void
    {
        $relativePath ??= basename($path);
        $relativePath = $this->normalizeZipPath($relativePath);

        if (is_dir($path)) {
            if ($relativePath !== '' && !$this->shouldTraverseDirectory($relativePath)) {
                return;
            }
            $zip->addEmptyDir($relativePath);
            $entries = scandir($path);
            if ($entries === false) {
                throw new CompressionException("Failed to read directory: {$path}");
            }

            foreach ($entries as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->addFilesToZipWithFilter($path . DIRECTORY_SEPARATOR . $file, $zip, "$relativePath/$file", $extensions);
                }
            }
        } elseif ((empty($extensions) || in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions)) && $this->shouldIncludePath($relativePath)) {
            $this->addArchiveEntry($zip, $path, $relativePath);
            $this->advanceProgress('compress', $relativePath);
        }
    }

    private function addFileToArchive(string $filePath, string $zipPath): void
    {
        if ($this->password !== null) {
            $this->zip->setPassword($this->password);
        }

        $added = $this->isLocalFilesystemPath($filePath)
            ? $this->zip->addFile($filePath, $zipPath)
            : $this->zip->addFromString($zipPath, FlysystemHelper::read($filePath));

        if (!$added) {
            throw new CompressionException("Failed to add file to ZIP: $filePath");
        }

        if ($this->password !== null) {
            $this->zip->setEncryptionName($zipPath, $this->encryptionAlgorithm);
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
            $this->zip->setPassword($this->password);
        }
    }

    private function attemptNativeDecompression(string $destination, bool $isRemoteDestination): bool
    {
        if (
            $this->executionStrategy === ExecutionStrategy::PHP
            || $this->password !== null
            || $isRemoteDestination
            || !NativeOperationsAdapter::canUseNativeCompression()
        ) {
            return false;
        }

        $this->closeZip();
        $native = NativeOperationsAdapter::decompressZip($this->workingZipPath, $destination);
        if ($native['success']) {
            if (is_callable($this->progressCallback)) {
                ($this->progressCallback)([
                    'operation' => 'decompress',
                    'path' => $this->zipFilePath,
                    'current' => 1,
                    'total' => 1,
                ]);
            }
            $this->openZip();

            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new CompressionException("Native decompression failed for archive: {$this->zipFilePath}");
        }

        $this->openZip();

        return false;
    }

    private function copyLocalDirectoryToFlysystem(string $localSource, string $destination): void
    {
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

            $targetPath = PathHelper::join($destination, $relative);

            if ($item->isDir()) {
                FlysystemHelper::createDirectory($targetPath);

                continue;
            }

            $stream = fopen($item->getPathname(), 'rb');
            if (!is_resource($stream)) {
                throw new CompressionException("Unable to read extracted file: {$item->getPathname()}");
            }

            try {
                FlysystemHelper::writeStream($targetPath, $stream);
            } finally {
                fclose($stream);
            }
        }
    }

    /**
     * @param list<string> $extensions
     */
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
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
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
        $extractTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_extract_', true);
        if (!$this->runSilently(static fn(): bool => mkdir($extractTempDir, 0755, true)) && !is_dir($extractTempDir)) {
            throw new CompressionException("Unable to create extraction directory: {$extractTempDir}");
        }

        return PathHelper::normalize($extractTempDir);
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

    private function extractArchive(string $extractDestination, string $destination, bool $isRemoteDestination): void
    {
        if (!$this->zip->extractTo($extractDestination)) {
            throw new CompressionException('Failed to extract ZIP archive.');
        }

        if ($isRemoteDestination) {
            $this->copyLocalDirectoryToFlysystem($extractDestination, $destination);
        }
    }

    /**
     * Build a ZIP-safe relative path.
     */
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

    /**
     * @param list<string> $extensions
     */
    private function initializeProgress(string $source, array $extensions = []): void
    {
        $this->progressCurrent = 0;
        $this->progressTotal = $this->countFilesForCompression($source, $extensions);
    }

    private function isLocalFilesystemPath(string $path): bool
    {
        return !PathHelper::hasScheme($path) && is_file($path);
    }

    private function isRemotePath(string $path): bool
    {
        return PathHelper::hasScheme($path) || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($path));
    }

    /**
     * @param list<string> $extensions
     */
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

    /**
     * @return ExtractionDestination
     */
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

        if (!FlysystemHelper::directoryExists($destination)) {
            FlysystemHelper::createDirectory($destination);
        }

        return [
            'extractDestination' => $destination,
            'extractTempDir' => null,
            'isRemote' => false,
        ];
    }

    private function resolveDecompressionDestination(?string $destination): string
    {
        $destination ??= $this->defaultDecompressionPath;
        if (!$destination) {
            throw new CompressionException('No destination path provided for decompression.');
        }

        return PathHelper::normalize($destination);
    }

    private function shouldAttemptNativeCompression(): bool
    {
        if ($this->executionStrategy === ExecutionStrategy::PHP) {
            return false;
        }

        // Native path currently targets whole-source archive operations only.
        return $this->password === null
            && $this->includePatterns === []
            && $this->excludePatterns === []
            && $this->ignorePatterns === []
            && $this->hooks === [];
    }
}
