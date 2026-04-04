<?php

namespace Infocyph\Pathwise\FileManager\Concerns;

use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

trait FsConcern
{
    private function doCopyFlysystemFileToLocal(string $sourcePath, string $localTarget): void
    {
        $this->doEnsureLocalDirectoryExists(dirname($localTarget));

        $stream = FlysystemHelper::readStream($sourcePath);
        $target = fopen($localTarget, 'wb');
        if (!is_resource($stream) || !is_resource($target)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw new CompressionException("Unable to read source path: {$sourcePath}");
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);
    }

    private function doEnsureLocalDirectoryExists(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        @mkdir($path, 0755, true);
    }

    private function doLoadIgnorePatterns(string $source): void
    {
        $this->ignorePatterns = [];

        if (!is_dir($source)) {
            return;
        }

        foreach ($this->ignoreFileNames as $fileName) {
            $ignoreFilePath = PathHelper::join($source, $fileName);
            if (!FlysystemHelper::fileExists($ignoreFilePath)) {
                continue;
            }

            $lines = preg_split('/\R/', FlysystemHelper::read($ignoreFilePath)) ?: [];
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $pattern = trim($line);
                if ($pattern === '' || str_starts_with($pattern, '#')) {
                    continue;
                }
                $this->ignorePatterns[] = str_replace('\\', '/', ltrim($pattern, './'));
            }
        }
    }

    private function doLocalizeCompressionSource(string $source, ?string &$cleanupPath = null): string
    {
        $normalizedSource = PathHelper::normalize($source);
        $cleanupPath = null;

        if (!PathHelper::hasScheme($normalizedSource) && (is_file($normalizedSource) || is_dir($normalizedSource))) {
            return $normalizedSource;
        }

        if (FlysystemHelper::fileExists($normalizedSource)) {
            $tempFile = tempnam(sys_get_temp_dir(), 'pathwise_src_');
            if ($tempFile === false) {
                throw new CompressionException("Unable to localize source path: {$source}");
            }

            $stream = FlysystemHelper::readStream($normalizedSource);
            $target = fopen($tempFile, 'wb');
            if (!is_resource($stream) || !is_resource($target)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                @unlink($tempFile);
                throw new CompressionException("Unable to localize source path: {$source}");
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            $cleanupPath = PathHelper::normalize($tempFile);

            return $cleanupPath;
        }

        if (FlysystemHelper::directoryExists($normalizedSource)) {
            $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_src_dir_', true);
            if (!@mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
                throw new CompressionException("Unable to localize source path: {$source}");
            }

            $cleanupPath = PathHelper::normalize($tempDir);
            $this->doMaterializeDirectoryToLocal($normalizedSource, $cleanupPath);

            return $cleanupPath;
        }

        throw new CompressionException("Source path does not exist: {$source}");
    }

    private function doMaterializeDirectoryToLocal(string $sourcePath, string $localDirectory): void
    {
        $base = $this->doResolveMaterializationBase($sourcePath);

        foreach (FlysystemHelper::listContents($sourcePath, true) as $item) {
            $relative = $this->doResolveMaterializedRelativePath($item, $base);
            if ($relative === null) {
                continue;
            }

            $localTarget = PathHelper::join($localDirectory, $relative);
            if (($item['type'] ?? null) === 'dir') {
                $this->doEnsureLocalDirectoryExists($localTarget);

                continue;
            }

            $resolvedPath = PathHelper::join($sourcePath, $relative);
            $this->doCopyFlysystemFileToLocal($resolvedPath, $localTarget);
        }
    }

    private function doResolveMaterializationBase(string $sourcePath): string
    {
        [, $baseLocation] = FlysystemHelper::resolveDirectory($sourcePath);

        return trim(str_replace('\\', '/', $baseLocation), '/');
    }

    private function doResolveMaterializedRelativePath(array $item, string $base): ?string
    {
        $itemPath = trim((string) ($item['path'] ?? ''), '/');
        if ($itemPath === '') {
            return null;
        }

        if ($base !== '' && str_starts_with($itemPath, $base . '/')) {
            return substr($itemPath, strlen($base) + 1);
        }

        if ($itemPath === $base) {
            return null;
        }

        return $itemPath;
    }

    private function doResolveWorkingZipPath(bool $create): string
    {
        if (!$this->isRemotePath($this->zipFilePath)) {
            return PathHelper::normalize($this->zipFilePath);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pathwise_zip_');
        if ($tempFile === false) {
            throw new CompressionException("Unable to allocate temporary ZIP path for {$this->zipFilePath}");
        }

        $this->cleanupWorkingZipPath = true;
        $this->syncWorkingZipOnClose = true;
        $normalizedTemp = PathHelper::normalize($tempFile);

        if (FlysystemHelper::fileExists($this->zipFilePath)) {
            $source = FlysystemHelper::readStream($this->zipFilePath);
            $target = fopen($normalizedTemp, 'wb');
            if (!is_resource($source) || !is_resource($target)) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new CompressionException("Unable to read ZIP archive: {$this->zipFilePath}");
            }

            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
        } elseif (!$create) {
            @unlink($normalizedTemp);
            throw new CompressionException("ZIP archive does not exist: {$this->zipFilePath}");
        } else {
            @unlink($normalizedTemp);
        }

        return $normalizedTemp;
    }

    private function doShouldIncludePath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if ($relativePath === '') {
            return true;
        }

        if ($this->includePatterns !== []) {
            $matchesInclude = false;
            foreach ($this->includePatterns as $pattern) {
                if (fnmatch($pattern, $relativePath)) {
                    $matchesInclude = true;
                    break;
                }
            }
            if (!$matchesInclude) {
                return false;
            }
        }

        return array_all(
            array_merge($this->excludePatterns, $this->ignorePatterns),
            fn($pattern) => !fnmatch($pattern, $relativePath)
        );
    }

    private function doShouldTraverseDirectory(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', trim($relativePath, '/'));
        if ($normalized === '') {
            return true;
        }

        foreach (array_merge($this->excludePatterns, $this->ignorePatterns) as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if (fnmatch(rtrim($pattern, '/'), $normalized) || fnmatch(rtrim($pattern, '/') . '/*', $normalized . '/x')) {
                return false;
            }
        }

        return true;
    }

    private function doSyncWorkingZipIfNeeded(): void
    {
        if (!$this->syncWorkingZipOnClose || !is_file($this->workingZipPath)) {
            return;
        }

        $stream = fopen($this->workingZipPath, 'rb');
        if (!is_resource($stream)) {
            throw new CompressionException("Unable to stream ZIP archive: {$this->workingZipPath}");
        }

        try {
            FlysystemHelper::writeStream($this->zipFilePath, $stream);
        } finally {
            fclose($stream);
        }
    }
}
