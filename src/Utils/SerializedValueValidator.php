<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

final class SerializedValueValidator
{
    private const int MAX_VISITS = 4096;

    public static function containsUnsupportedValue(mixed $value, int $depth = 0): bool
    {
        $remaining = self::MAX_VISITS;

        return self::isUnsupported($value, $depth, $remaining);
    }

    private static function isUnsupported(mixed $value, int $depth, int &$remaining): bool
    {
        if ($depth > 256 || --$remaining < 0) {
            return true;
        }
        if (is_float($value)) {
            return !is_finite($value);
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return false;
        }
        if (!is_array($value)) {
            return true;
        }

        return array_any(
            $value,
            static function (mixed $item) use ($depth, &$remaining): bool {
                return self::isUnsupported($item, $depth + 1, $remaining);
            },
        );
    }
}
