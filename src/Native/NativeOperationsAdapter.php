<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Native;

use Infocyph\Pathwise\Results\NativeExecutionResult;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

final class NativeOperationsAdapter
{
    public static function canUseNativeCompression(): bool
    {
        return self::canUseNativeZipCompression() && self::canUseNativeZipDecompression();
    }

    public static function canUseNativeDirectoryCopy(): bool
    {
        return NativeCommandRunner::commandExists(PHP_OS_FAMILY === 'Windows' ? 'robocopy' : 'rsync');
    }

    public static function canUseNativeFileCopy(): bool
    {
        return NativeCommandRunner::commandExists(PHP_OS_FAMILY === 'Windows' ? 'powershell' : 'cp');
    }

    public static function canUseNativeSearch(): bool
    {
        return NativeCommandRunner::commandExists(PHP_OS_FAMILY === 'Windows' ? 'findstr' : 'grep');
    }

    public static function canUseNativeZipCompression(): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? NativeCommandRunner::commandExists('powershell')
            : NativeCommandRunner::commandExists('zip');
    }

    public static function canUseNativeZipDecompression(): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? NativeCommandRunner::commandExists('powershell')
            : NativeCommandRunner::commandExists('unzip');
    }

    public static function compressToZip(
        string $source,
        string $zipPath,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        $source = PathHelper::normalize($source);
        $zipPath = PathHelper::normalize($zipPath);

        if (PHP_OS_FAMILY === 'Windows' && NativeCommandRunner::commandExists('powershell')) {
            $sourceArgument = is_dir($source)
                ? rtrim($source, '/\\') . DIRECTORY_SEPARATOR . '*'
                : $source;
            $sourcePattern = str_replace("'", "''", $sourceArgument);
            $destination = str_replace("'", "''", $zipPath);

            return self::run([
                'powershell',
                '-NoProfile',
                '-Command',
                "Compress-Archive -Path '{$sourcePattern}' -DestinationPath '{$destination}' -Force",
            ], limits: $limits);
        }

        if (!NativeCommandRunner::commandExists('zip')) {
            return self::unsupportedResult();
        }

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

        if (PHP_OS_FAMILY === 'Windows') {
            if (!NativeCommandRunner::commandExists('robocopy')) {
                return self::unsupportedResult();
            }
            $result = self::run([
                'robocopy',
                $source,
                $destination,
                $mirror ? '/MIR' : '/E',
                '/R:1',
                '/W:1',
                '/NFL',
                '/NDL',
                '/NJH',
                '/NJS',
                '/NP',
            ], limits: $limits);

            if ($result->failure !== null && $result->failure !== NativeExecutionFailure::EXIT_CODE) {
                return $result;
            }

            $success = $result->exitCode >= 0 && $result->exitCode <= 7;

            return new NativeExecutionResult(
                $success,
                $result->command,
                $result->exitCode,
                $result->output,
                $success ? null : NativeExecutionFailure::EXIT_CODE,
                $result->stdout,
                $result->stderr,
            );
        }

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
        if (PHP_OS_FAMILY === 'Windows') {
            if (!NativeCommandRunner::commandExists('powershell')) {
                return self::unsupportedResult();
            }
            $literalSource = str_replace("'", "''", $source);
            $literalDestination = str_replace("'", "''", $destination);

            return self::run([
                'powershell',
                '-NoProfile',
                '-Command',
                "Copy-Item -LiteralPath '{$literalSource}' -Destination '{$literalDestination}' -Force",
            ], limits: $limits);
        }

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
        if (PHP_OS_FAMILY === 'Windows') {
            if (!NativeCommandRunner::commandExists('powershell')) {
                return self::unsupportedResult();
            }
            $source = str_replace("'", "''", $zipPath);
            $target = str_replace("'", "''", $destination);

            return self::run([
                'powershell',
                '-NoProfile',
                '-Command',
                "Expand-Archive -LiteralPath '{$source}' -DestinationPath '{$target}' -Force",
            ], limits: $limits);
        }

        return NativeCommandRunner::commandExists('unzip')
            ? self::run(['unzip', '-q', '-o', $zipPath, '-d', $destination], limits: $limits)
            : self::unsupportedResult();
    }

    public static function searchFile(
        string $path,
        string $term,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        if (PHP_OS_FAMILY === 'Windows') {
            return NativeCommandRunner::commandExists('findstr')
                ? self::run(['findstr', '/I', '/L', $term, PathHelper::normalize($path)], limits: $limits)
                : self::unsupportedResult();
        }

        return NativeCommandRunner::commandExists('grep')
            ? self::run(['grep', '-i', '-F', '--', $term, PathHelper::normalize($path)], limits: $limits)
            : self::unsupportedResult();
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
            ['Required native executable is unavailable.'],
            NativeExecutionFailure::UNSUPPORTED,
        );
    }
}
