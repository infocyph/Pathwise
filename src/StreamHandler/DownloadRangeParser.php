<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

use Infocyph\Pathwise\Exceptions\DownloadException;

/** Parses a single HTTP byte-range without retaining request-scoped state. */
final class DownloadRangeParser
{
    /** @return array{int|null, int|null, bool} */
    public static function resolve(?string $rangeHeader, int $size, bool $enabled): array
    {
        if ($size === 0) {
            if ($rangeHeader !== null && trim($rangeHeader) !== '' && $enabled) {
                throw new DownloadException('Byte range is unsatisfiable for an empty file.');
            }

            return [null, null, false];
        }
        if (!$enabled || $rangeHeader === null || trim($rangeHeader) === '') {
            return [0, $size - 1, false];
        }
        if (preg_match('/^\s*bytes=(\d*)-(\d*)\s*$/', $rangeHeader, $matches) !== 1) {
            throw new DownloadException('Invalid range header.');
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];
        if ($startRaw === '' && $endRaw === '') {
            throw new DownloadException('Invalid range header.');
        }
        if ($startRaw === '') {
            return self::resolveSuffixRange($endRaw, $size);
        }

        return self::resolveExplicitRange($startRaw, $endRaw, $size);
    }

    /** @return array{int, int, true} */
    private static function resolveExplicitRange(string $startRaw, string $endRaw, int $size): array
    {
        $start = (int) $startRaw;
        if ($start < 0 || $start >= $size) {
            throw new DownloadException('Invalid range header.');
        }
        if ($endRaw === '') {
            return [$start, $size - 1, true];
        }

        $end = (int) $endRaw;
        if ($end < $start) {
            throw new DownloadException('Invalid range header.');
        }

        return [$start, min($end, $size - 1), true];
    }

    /** @return array{int, int, true} */
    private static function resolveSuffixRange(string $endRaw, int $size): array
    {
        $suffixLength = (int) $endRaw;
        if ($suffixLength <= 0) {
            throw new DownloadException('Invalid range header.');
        }

        return [max(0, $size - $suffixLength), $size - 1, true];
    }
}
