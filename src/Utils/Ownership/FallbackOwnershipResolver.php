<?php

namespace Infocyph\Pathwise\Utils\Ownership;

final class FallbackOwnershipResolver implements OwnershipResolverInterface
{
    public function getLastModifiedBy(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $user = get_current_user();
        return $user !== '' ? $user : null;
    }
    public function getOwnershipDetails(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        return [
            'owner' => null,
            'group' => null,
        ];
    }
}
