<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class QueueProcessResult
{
    public function __construct(public int $processed, public int $failed) {}
}
