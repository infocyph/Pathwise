<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

use InvalidArgumentException;

final readonly class QueueProcessResult
{
    public function __construct(public int $processed, public int $failed)
    {
        if ($processed < 0 || $failed < 0) {
            throw new InvalidArgumentException('Queue process counts must be non-negative.');
        }
    }
}
