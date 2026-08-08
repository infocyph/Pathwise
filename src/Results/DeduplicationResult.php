<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class DeduplicationResult
{
    /**
     * @param list<string> $linked
     * @param list<string> $skipped
     */
    public function __construct(public array $linked, public array $skipped) {}
}
