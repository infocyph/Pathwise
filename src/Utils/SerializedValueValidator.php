<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

final class SerializedValueValidator
{
    public static function containsUnsupportedValue(mixed $value, int $depth = 0): bool
    {
        if ($depth > 256) {
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

        return array_any($value, static fn(mixed $item): bool => self::containsUnsupportedValue($item, $depth + 1));
    }
}
