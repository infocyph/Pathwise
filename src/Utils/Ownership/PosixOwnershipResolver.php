<?php

namespace Infocyph\Pathwise\Utils\Ownership;

final class PosixOwnershipResolver implements OwnershipResolverInterface
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

        $ownerId = fileowner($path);
        if ($ownerId === false) {
            return null;
        }

        $ownerInfo = posix_getpwuid($ownerId);
        return is_array($ownerInfo) ? ($ownerInfo['name'] ?? null) : null;
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

        $ownerId = fileowner($path);
        $groupId = filegroup($path);
        if ($ownerId === false || $groupId === false) {
            return null;
        }

        $owner = posix_getpwuid($ownerId)['name'] ?? null;
        $group = posix_getgrgid($groupId)['name'] ?? null;

        return compact('owner', 'group');
    }
}
