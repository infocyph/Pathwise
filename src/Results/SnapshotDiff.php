<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class SnapshotDiff
{
    /**
     * @param list<string> $created
     * @param list<string> $modified
     * @param list<string> $deleted
     */
    public function __construct(public array $created, public array $modified, public array $deleted) {}

    public function isEmpty(): bool
    {
        return $this->created === [] && $this->modified === [] && $this->deleted === [];
    }
}
