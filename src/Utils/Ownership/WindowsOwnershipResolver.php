<?php

namespace Infocyph\Pathwise\Utils\Ownership;

final class WindowsOwnershipResolver implements OwnershipResolverInterface
{
    public function getLastModifiedBy(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $owner = getenv('USERNAME');
        if (is_string($owner) && $owner !== '') {
            return $owner;
        }

        $currentUser = get_current_user();
        return $currentUser !== '' ? $currentUser : null;
    }
    public function getOwnershipDetails(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $owner = getenv('USERNAME');
        if (!is_string($owner) || $owner === '') {
            $owner = get_current_user() ?: null;
        }

        $group = getenv('USERDOMAIN');
        if (!is_string($group) || $group === '') {
            $group = null;
        }

        return compact('owner', 'group');
    }
}
