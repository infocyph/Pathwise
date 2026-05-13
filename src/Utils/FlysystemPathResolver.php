<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

final class FlysystemPathResolver
{
    public static function intFromMixed(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function relativePathFromItem(mixed $item, string $base, ?string $requiredType = null): ?string
    {
        if (!is_array($item)) {
            return null;
        }

        if ($requiredType !== null) {
            $type = $item['type'] ?? null;
            if (!is_string($type) || $type !== $requiredType) {
                return null;
            }
        }

        $itemPathRaw = $item['path'] ?? null;
        if (!is_string($itemPathRaw)) {
            return null;
        }

        return self::relativePathFromRawPath($itemPathRaw, $base);
    }

    public static function relativePathFromRawPath(string $itemPathRaw, string $base): ?string
    {
        $itemPath = trim($itemPathRaw, '/');
        if ($itemPath === '') {
            return null;
        }

        $relative = $base !== '' && str_starts_with($itemPath, $base . '/')
            ? substr($itemPath, strlen($base) + 1)
            : ($itemPath === $base ? '' : $itemPath);

        return $relative === '' ? null : $relative;
    }

    public static function resolveDirectoryBase(string $directory): string
    {
        [, $baseLocation] = FlysystemHelper::resolveDirectory($directory);

        return trim(str_replace('\\', '/', $baseLocation), '/');
    }
}
