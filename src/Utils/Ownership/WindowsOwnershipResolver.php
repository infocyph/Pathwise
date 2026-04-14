<?php

namespace Infocyph\Pathwise\Utils\Ownership;

final class WindowsOwnershipResolver implements OwnershipResolverInterface
{
    /**
     * Get the username of the user who last modified the file.
     *
     * @param string $path The file path.
     * @return string|null The username, or null if the file doesn't exist.
     */
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
    /**
     * Get ownership details for a file.
     *
     * @param string $path The file path.
     * @return array|null Array with 'owner' and 'group' keys, or null if the file doesn't exist.
     */
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
