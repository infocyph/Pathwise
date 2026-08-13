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

    public static function compressToZip(string $source, string $zipPath): NativeExecutionResult
    {
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
            ]);
        }

        if (!NativeCommandRunner::commandExists('zip')) {
            return self::unsupportedResult();
        }

        if (is_dir($source)) {
            $command = ['zip', '-r', $zipPath, '.'];
            if (FlysystemHelper::isSameOrDescendant($source, $zipPath)) {
                $command[] = '-x';
                $command[] = basename($zipPath);
            }

            return self::run($command, $source);
        }

        return self::run(['zip', '-r', $zipPath, basename($source)], dirname($source));
    }

    public static function copyDirectory(
        string $source,
        string $destination,
        bool $mirror = false,
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
            ]);

            return new NativeExecutionResult(
                $result->exitCode <= 7,
                $result->command,
                $result->exitCode,
                $result->output,
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

        return self::run($command);
    }

    public static function copyFile(string $source, string $destination): NativeExecutionResult
    {
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
            ]);
        }

        return NativeCommandRunner::commandExists('cp')
            ? self::run(['cp', '-f', $source, $destination])
            : self::unsupportedResult();
    }

    public static function decompressZip(string $zipPath, string $destination): NativeExecutionResult
    {
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
            ]);
        }

        return NativeCommandRunner::commandExists('unzip')
            ? self::run(['unzip', '-o', $zipPath, '-d', $destination])
            : self::unsupportedResult();
    }

    public static function searchFile(string $path, string $term): NativeExecutionResult
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return NativeCommandRunner::commandExists('findstr')
                ? self::run(['findstr', '/I', '/L', $term, PathHelper::normalize($path)])
                : self::unsupportedResult();
        }

        return NativeCommandRunner::commandExists('grep')
            ? self::run(['grep', '-i', '-F', '--', $term, PathHelper::normalize($path)])
            : self::unsupportedResult();
    }

    /** @param list<string> $command */
    private static function displayCommand(array $command): string
    {
        return implode(' ', array_map(
            static fn(string $argument): string => json_encode($argument, JSON_UNESCAPED_SLASHES) ?: '""',
            $command,
        ));
    }

    /** @param list<string> $command */
    private static function run(array $command, ?string $workingDirectory = null): NativeExecutionResult
    {
        $result = NativeCommandRunner::run($command, $workingDirectory);

        return new NativeExecutionResult(
            $result['success'],
            self::displayCommand($command),
            $result['code'],
            $result['output'],
        );
    }

    private static function unsupportedResult(): NativeExecutionResult
    {
        return new NativeExecutionResult(false, '', 127, ['Required native executable is unavailable.']);
    }
}
