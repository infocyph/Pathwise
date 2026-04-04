<?php

namespace Infocyph\Pathwise\Utils\Ownership;

final class OwnershipResolverFactory
{
    public static function create(): OwnershipResolverInterface
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return new WindowsOwnershipResolver();
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_getgrgid')) {
            return new PosixOwnershipResolver();
        }

        return new FallbackOwnershipResolver();
    }
}
