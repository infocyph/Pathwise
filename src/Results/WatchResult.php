<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class WatchResult
{
    /** @param array<string, array{mtime: int, size: int}> $finalSnapshot */
    public function __construct(public array $finalSnapshot, public int $changeSets) {}
}
