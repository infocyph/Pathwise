<?php

namespace Infocyph\Pathwise\Utils\Ownership;

interface OwnershipResolverInterface
{
    public function getLastModifiedBy(string $path): ?string;
    /**
     * @return array{owner: ?string, group: ?string}|null
     */
    public function getOwnershipDetails(string $path): ?array;
}
