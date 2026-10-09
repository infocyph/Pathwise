<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Native;

use FilesystemIterator;
use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Results\NativeExecutionResult;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class NativeOperationsAdapter
{
    public static function canUseNativeCompression(): bool
    {
        return self::canUseNativeZipCompression() && self::canUseNativeZipDecompression();
    }

    public static function canUseNativeDirectoryCopy(): bool
    {
        return NativeCommandRunner::commandExists('rsync');
    }

    public static function canUseNativeFileCopy(): bool
    {
        return NativeCommandRunner::commandExists('cp');
    }

    public static function canUseNativeSearch(): bool
    {
        return NativeCommandRunner::commandExists('grep');
    }

    public static function canUseNativeZipCompression(): bool
    {
        return NativeCommandRunner::commandExists('zip');
    }

    public static function canUseNativeZipDecompression(): bool
    {
        return NativeCommandRunner::commandExists('unzip');
    }

    public static function compressToZip(
        string $source,
        string $zipPath,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        $source = PathHelper::normalize($source);
        $zipPath = PathHelper::normalize($zipPath);
        if (!NativeCommandRunner::commandExists('zip')) {
            return self::unsupportedResult();
        }
        self::assertZipSourceWithoutLinks($source);

        if (is_dir($source)) {
            $command = ['zip', '-q', '-r', $zipPath, '.'];
            if (FlysystemHelper::isSameOrDescendant($source, $zipPath)) {
                $command[] = '-x';
                $command[] = basename($zipPath);
            }

            return self::run($command, $source, $limits);
        }

        return self::run(['zip', '-q', '-r', $zipPath, basename($source)], dirname($source), $limits);
    }

    public static function copyDirectory(
        string $source,
        string $destination,
        bool $mirror = false,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        $source = PathHelper::normalize($source);
        $destination = PathHelper::normalize($destination);
        if (!NativeCommandRunner::commandExists('rsync')) {
            return self::unsupportedResult();
        }

        $command = ['rsync', '-a'];
        if ($mirror) {
            $command[] = '--delete';
        }
        $command[] = rtrim($source, '/\\') . DIRECTORY_SEPARATOR;
        $command[] = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR;

        return self::run($command, limits: $limits);
    }

    public static function copyFile(
        string $source,
        string $destination,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        $source = PathHelper::normalize($source);
        $destination = PathHelper::normalize($destination);

        return NativeCommandRunner::commandExists('cp')
            ? self::run(['cp', '-f', $source, $destination], limits: $limits)
            : self::unsupportedResult();
    }

    public static function decompressZip(
        string $zipPath,
        string $destination,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        $zipPath = PathHelper::normalize($zipPath);
        $destination = PathHelper::normalize($destination);

        return NativeCommandRunner::commandExists('unzip')
            ? self::run(['unzip', '-q', '-o', $zipPath, '-d', $destination], limits: $limits)
            : self::unsupportedResult();
    }

    public static function searchFile(
        string $path,
        string $term,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        return NativeCommandRunner::commandExists('grep')
            ? self::run(['grep', '-i', '-F', '--', $term, PathHelper::normalize($path)], limits: $limits)
            : self::unsupportedResult();
    }

    private static function assertZipSourceWithoutLinks(string $source): void
    {
        if (is_link($source)) {
            throw new CompressionException('Symbolic links are not followed during ZIP creation.');
        }
        if (!is_dir($source)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isLink()) {
                throw new CompressionException('Symbolic links are not followed during ZIP creation.');
            }
        }
    }

    /** @param list<string> $command */
    private static function run(
        array $command,
        ?string $workingDirectory = null,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        return NativeCommandRunner::run($command, $workingDirectory, $limits);
    }

    private static function unsupportedResult(): NativeExecutionResult
    {
        return new NativeExecutionResult(
            false,
            '',
            127,
            ['Bounded native execution or the required native executable is unavailable.'],
            NativeExecutionFailure::UNSUPPORTED,
        );
    }
}
