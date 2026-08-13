<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

use InvalidArgumentException;

final readonly class DownloadStreamResult
{
    public function __construct(public DownloadPreparation $preparation, public int $bytesSent)
    {
        if ($bytesSent < 0 || $bytesSent > $preparation->range->contentLength) {
            throw new InvalidArgumentException('Invalid streamed byte count.');
        }
    }
}
