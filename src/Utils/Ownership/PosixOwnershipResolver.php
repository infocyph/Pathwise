<?php

namespace Infocyph\Pathwise\Utils\Ownership;

final class PosixOwnershipResolver implements OwnershipResolverInterface
{
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
