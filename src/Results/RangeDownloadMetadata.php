<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class RangeDownloadMetadata
{
    public function __construct(
        public int $start,
        public int $end,
        public int $contentLength,
        public bool $partial,
    ) {}
}
