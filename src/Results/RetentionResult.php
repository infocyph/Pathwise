<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class RetentionResult
{
    /**
     * @param list<string> $deleted
     * @param list<string> $kept
     */
    public function __construct(public array $deleted, public array $kept) {}
}
