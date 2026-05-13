<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

final class ExtensionPolicy
{
    public const string ERROR_BLOCKED = 'blocked';

    public const string ERROR_DISALLOWED = 'disallowed';

    public const string ERROR_REQUIRED = 'required';

    public static function messageFor(
        string $error,
        string $requiredMessage,
        string $blockedMessage,
        string $disallowedMessage,
        string $fallbackMessage = 'Invalid file extension.',
    ): string {
        return match ($error) {
            self::ERROR_REQUIRED => $requiredMessage,
            self::ERROR_BLOCKED => $blockedMessage,
            self::ERROR_DISALLOWED => $disallowedMessage,
            default => $fallbackMessage,
        };
    }

    /**
     * @param list<string> $allowedExtensions
     * @param list<string> $blockedExtensions
     */
    public static function validate(string $extension, array $allowedExtensions, array $blockedExtensions): ?string
    {
        if ($extension === '') {
            return $allowedExtensions !== [] ? self::ERROR_REQUIRED : null;
        }

        if (in_array($extension, $blockedExtensions, true)) {
            return self::ERROR_BLOCKED;
        }

        if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            return self::ERROR_DISALLOWED;
        }

        return null;
    }
}
