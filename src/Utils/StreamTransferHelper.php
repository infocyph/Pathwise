<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

final class StreamTransferHelper
{
    public static function copyLocalFileToPath(string $localSource, string $destination): void
    {
        $stream = fopen($localSource, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException("Unable to stream local file: {$localSource}");
        }

        try {
            FlysystemHelper::writeStream($destination, $stream);
        } finally {
            fclose($stream);
        }
    }

    public static function syncLocalFileToPath(bool $enabled, ?string $localSource, string $destination): bool
    {
        if (!$enabled || !is_string($localSource) || !is_file($localSource)) {
            return false;
        }

        self::copyLocalFileToPath($localSource, $destination);

        return true;
    }

    /**
     * @param callable(): \Throwable $exceptionFactory
     */
    public static function syncLocalFileToPathOrThrow(
        bool $enabled,
        ?string $localSource,
        string $destination,
        callable $exceptionFactory,
    ): void {
        try {
            self::syncLocalFileToPath($enabled, $localSource, $destination);
        } catch (\Throwable) {
            throw $exceptionFactory();
        }
    }
}
