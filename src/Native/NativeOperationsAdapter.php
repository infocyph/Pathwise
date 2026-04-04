<?php

namespace Infocyph\Pathwise\Native;

use Infocyph\Pathwise\Utils\PathHelper;

final class NativeOperationsAdapter
{
    public static function canUseNativeCompression(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return NativeCommandRunner::commandExists('powershell') || NativeCommandRunner::commandExists('tar');
        }

        return NativeCommandRunner::commandExists('zip') && NativeCommandRunner::commandExists('unzip');
    }
    public static function canUseNativeDirectoryCopy(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return NativeCommandRunner::commandExists('robocopy');
        }

        return NativeCommandRunner::commandExists('rsync');
    }

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
            $command = sprintf(
                'zip -r %s %s',
                escapeshellarg($zipPath),
                escapeshellarg(basename($source)),
            );
            $cwd = dirname($source);
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

        if (PHP_OS_FAMILY === 'Windows' && NativeCommandRunner::commandExists('robocopy')) {
            $flags = $mirror ? '/MIR' : '/E';
            $command = sprintf(
                'robocopy %s %s %s /R:1 /W:1 /NFL /NDL /NJH /NJS /NP',
                escapeshellarg($source),
                escapeshellarg($destination),
                $flags,
            );
            $result = NativeCommandRunner::run($command);

            // Robocopy exit codes 0-7 are considered successful copies.
            $success = $result['code'] <= 7;

            return [
                'success' => $success,
                'command' => $command,
                'code' => $result['code'],
            ];
        }

        if (NativeCommandRunner::commandExists('rsync')) {
            $deleteFlag = $mirror ? ' --delete' : '';
            $command = sprintf(
                'rsync -a%s %s/ %s/',
                $deleteFlag,
                escapeshellarg($source),
                escapeshellarg($destination),
            );
            $result = NativeCommandRunner::run($command);

            return [
                'success' => $result['success'],
                'command' => $command,
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
    public static function copyFile(string $source, string $destination): array
    {
        $source = PathHelper::normalize($source);
        $destination = PathHelper::normalize($destination);

        if (PHP_OS_FAMILY === 'Windows' && NativeCommandRunner::commandExists('cmd')) {
            $command = sprintf(
                'cmd /C copy /Y %s %s >NUL',
                escapeshellarg($source),
                escapeshellarg($destination),
            );
            $result = NativeCommandRunner::run($command);

            return [
                'success' => $result['success'],
                'command' => $command,
                'code' => $result['code'],
            ];
        }

        if (NativeCommandRunner::commandExists('cp')) {
            $command = sprintf(
                'cp -f %s %s',
                escapeshellarg($source),
                escapeshellarg($destination),
            );
            $result = NativeCommandRunner::run($command);

            return [
                'success' => $result['success'],
                'command' => $command,
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
    public static function decompressZip(string $zipPath, string $destination): array
    {
        $zipPath = PathHelper::normalize($zipPath);
        $destination = PathHelper::normalize($destination);

        if (PHP_OS_FAMILY === 'Windows' && NativeCommandRunner::commandExists('powershell')) {
            $command = sprintf(
                'powershell -NoProfile -Command "Expand-Archive -Path %s -DestinationPath %s -Force"',
                escapeshellarg($zipPath),
                escapeshellarg($destination),
            );
            $result = NativeCommandRunner::run($command);

            return [
                'success' => $result['success'],
                'command' => $command,
                'code' => $result['code'],
            ];
        }

        if (NativeCommandRunner::commandExists('unzip')) {
            $command = sprintf(
                'unzip -o %s -d %s',
                escapeshellarg($zipPath),
                escapeshellarg($destination),
            );
            $result = NativeCommandRunner::run($command);

            return [
                'success' => $result['success'],
                'command' => $command,
                'code' => $result['code'],
            ];
        }

        return [
            'success' => false,
            'command' => '',
            'code' => 127,
        ];
    }
}
