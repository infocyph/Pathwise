<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

use Infocyph\Pathwise\Exceptions\FileAccessException;

final class ReadablePathLocalizer
{
    /** @return array{path: string, cleanup: bool} */
    public static function resolve(string $filename, ?string $existingLocalPath = null): array
    {
        $normalized = PathHelper::normalize($filename);
        $preferFlysystem = FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($normalized);
        if (!$preferFlysystem && !PathHelper::hasScheme($normalized) && is_file($normalized)) {
            return ['path' => $normalized, 'cleanup' => false];
        }
        if (!FlysystemHelper::fileExists($normalized)) {
            throw self::inaccessible($filename);
        }
        if (is_string($existingLocalPath) && is_file($existingLocalPath)) {
            return ['path' => $existingLocalPath, 'cleanup' => true];
        }

        return ['path' => self::copyToTemporaryFile($normalized, $filename), 'cleanup' => true];
    }

    private static function copyToTemporaryFile(string $source, string $original): string
    {
        $input = FlysystemHelper::readStream($source);
        if (!is_resource($input)) {
            throw self::inaccessible($original);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'pathwise_reader_');
        if ($temporary === false) {
            fclose($input);

            throw self::inaccessible($original);
        }
        $output = fopen($temporary, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            self::unlinkSilently($temporary);

            throw self::inaccessible($original);
        }

        $failure = null;

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                $failure = self::inaccessible($original);
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($failure instanceof \Throwable) {
            self::unlinkSilently($temporary);

            throw $failure;
        }

        return PathHelper::normalize($temporary);
    }

    private static function inaccessible(string $path): FileAccessException
    {
        return new FileAccessException("Cannot access file at path: {$path}");
    }

    private static function unlinkSilently(string $path): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            unlink($path);
        } finally {
            restore_error_handler();
        }
    }
}
