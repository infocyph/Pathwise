<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Native;

use Infocyph\Pathwise\Utils\PathHelper;

final class NativeOperationsAdapter
{
    /**
     * Check if native compression commands are available.
     *
     * @return bool True if native compression is available.
     */
    public static function canUseNativeCompression(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return NativeCommandRunner::commandExists('powershell') || NativeCommandRunner::commandExists('tar');
        }

        return NativeCommandRunner::commandExists('zip') && NativeCommandRunner::commandExists('unzip');
    }

    /**
     * Check if native directory copy commands are available.
     *
     * @return bool True if native directory copy is available.
     */
    public static function canUseNativeDirectoryCopy(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return NativeCommandRunner::commandExists('robocopy');
        }

        return NativeCommandRunner::commandExists('rsync');
    }

    /**
     * Check if native file copy commands are available.
     *
     * @return bool True if native file copy is available.
     */
    public static function canUseNativeFileCopy(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return NativeCommandRunner::commandExists('cmd');
        }

        return NativeCommandRunner::commandExists('cp');
    }

    /**
     * @return array{success: bool, command: string, code: int}
     */
    public static function compressToZip(string $source, string $zipPath): array
    {
        $source = PathHelper::normalize($source);
        $zipPath = PathHelper::normalize($zipPath);

        if (PHP_OS_FAMILY === 'Windows' && NativeCommandRunner::commandExists('powershell')) {
            $command = sprintf(
                'powershell -NoProfile -Command "Compress-Archive -Path %s -DestinationPath %s -Force"',
                escapeshellarg($source . DIRECTORY_SEPARATOR . '*'),
                escapeshellarg($zipPath),
            );
            $result = NativeCommandRunner::run($command);

            return [
                'success' => $result['success'],
                'command' => $command,
                'code' => $result['code'],
            ];
        }

        if (NativeCommandRunner::commandExists('zip')) {
            $zipArg = escapeshellarg($zipPath);
            $cwd = dirname($source);

            if (is_dir($source)) {
                $cwd = $source;
                $command = sprintf('zip -r %s .', $zipArg);

                $zipParent = PathHelper::normalize(dirname($zipPath));
                if ($zipParent === PathHelper::normalize($source)) {
                    $command .= ' -x ' . escapeshellarg(basename($zipPath));
                }
            } else {
                $command = sprintf(
                    'zip -r %s %s',
                    $zipArg,
                    escapeshellarg(basename($source)),
                );
            }

            $wrapped = sprintf('cd %s && %s', escapeshellarg($cwd), $command);
            $result = NativeCommandRunner::run($wrapped);

            return [
                'success' => $result['success'],
                'command' => $wrapped,
                'code' => $result['code'],
            ];
        }

        return [
            'success' => false,
            'command' => '',
            'code' => 127,
        ];
    }

    /**
     * @return array{success: bool, command: string, code: int}
     */
    public static function copyDirectory(string $source, string $destination, bool $mirror = false): array
    {
        $source = PathHelper::normalize($source);
        $destination = PathHelper::normalize($destination);

        if (PHP_OS_FAMILY === 'Windows') {
            $flags = $mirror ? '/MIR' : '/E';
            $result = self::runCommandIfAvailable(
                'robocopy',
                static fn(): string => sprintf(
                    'robocopy %s %s %s /R:1 /W:1 /NFL /NDL /NJH /NJS /NP',
                    escapeshellarg($source),
                    escapeshellarg($destination),
                    $flags,
                ),
                static fn(array $result): bool => $result['code'] <= 7,
            );
            if ($result !== null) {
                return $result;
            }
        }

        $deleteFlag = $mirror ? ' --delete' : '';
        $result = self::runCommandIfAvailable(
            'rsync',
            static fn(): string => sprintf(
                'rsync -a%s %s/ %s/',
                $deleteFlag,
                escapeshellarg($source),
                escapeshellarg($destination),
            ),
        );
        if ($result !== null) {
            return $result;
        }

        return self::unsupportedResult();
    }

    /**
     * @return array{success: bool, command: string, code: int}
     */
    public static function copyFile(string $source, string $destination): array
    {
        return self::runDualPathOperation(
            $source,
            $destination,
            'cmd',
            static fn(string $normalizedSource, string $normalizedDestination): string => sprintf(
                'cmd /C copy /Y %s %s >NUL',
                escapeshellarg($normalizedSource),
                escapeshellarg($normalizedDestination),
            ),
            'cp',
            static fn(string $normalizedSource, string $normalizedDestination): string => sprintf(
                'cp -f %s %s',
                escapeshellarg($normalizedSource),
                escapeshellarg($normalizedDestination),
            ),
        );
    }

    /**
     * @return array{success: bool, command: string, code: int}
     */
    public static function decompressZip(string $zipPath, string $destination): array
    {
        return self::runDualPathOperation(
            $zipPath,
            $destination,
            'powershell',
            static fn(string $normalizedZipPath, string $normalizedDestination): string => sprintf(
                'powershell -NoProfile -Command "Expand-Archive -Path %s -DestinationPath %s -Force"',
                escapeshellarg($normalizedZipPath),
                escapeshellarg($normalizedDestination),
            ),
            'unzip',
            static fn(string $normalizedZipPath, string $normalizedDestination): string => sprintf(
                'unzip -o %s -d %s',
                escapeshellarg($normalizedZipPath),
                escapeshellarg($normalizedDestination),
            ),
        );
    }

    /**
     * @param callable(): string $commandBuilder
     * @param callable(array{success: bool, output: array<int, string>, code: int}): bool|null $successResolver
     * @return array{success: bool, command: string, code: int}|null
     */
    private static function runCommandIfAvailable(
        string $command,
        callable $commandBuilder,
        ?callable $successResolver = null,
    ): ?array {
        if (!NativeCommandRunner::commandExists($command)) {
            return null;
        }

        $builtCommand = $commandBuilder();
        $result = NativeCommandRunner::run($builtCommand);

        return [
            'success' => $successResolver !== null ? (bool) $successResolver($result) : $result['success'],
            'command' => $builtCommand,
            'code' => $result['code'],
        ];
    }

    /**
     * @param callable(string, string): string $windowsCommandBuilder
     * @param callable(string, string): string $unixCommandBuilder
     * @return array{success: bool, command: string, code: int}
     */
    private static function runDualPathOperation(
        string $sourcePath,
        string $destinationPath,
        string $windowsCommand,
        callable $windowsCommandBuilder,
        string $unixCommand,
        callable $unixCommandBuilder,
    ): array {
        $normalizedSourcePath = PathHelper::normalize($sourcePath);
        $normalizedDestinationPath = PathHelper::normalize($destinationPath);

        return self::runWindowsThenUnix(
            $windowsCommand,
            static fn(): string => $windowsCommandBuilder($normalizedSourcePath, $normalizedDestinationPath),
            $unixCommand,
            static fn(): string => $unixCommandBuilder($normalizedSourcePath, $normalizedDestinationPath),
        );
    }

    /**
     * @param callable(): string $windowsCommandBuilder
     * @param callable(): string $unixCommandBuilder
     * @return array{success: bool, command: string, code: int}
     */
    private static function runWindowsThenUnix(
        string $windowsCommand,
        callable $windowsCommandBuilder,
        string $unixCommand,
        callable $unixCommandBuilder,
    ): array {
        if (PHP_OS_FAMILY === 'Windows') {
            $windowsResult = self::runCommandIfAvailable($windowsCommand, $windowsCommandBuilder);
            if ($windowsResult !== null) {
                return $windowsResult;
            }
        }

        return self::runCommandIfAvailable($unixCommand, $unixCommandBuilder) ?? self::unsupportedResult();
    }

    /**
     * @return array{success: bool, command: string, code: int}
     */
    private static function unsupportedResult(): array
    {
        return [
            'success' => false,
            'command' => '',
            'code' => 127,
        ];
    }
}
