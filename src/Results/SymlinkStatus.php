<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class SymlinkStatus
{
    public function __construct(
        public string $link,
        public string $target,
        public bool $exists,
        public bool $linked,
        public bool $matches,
        public bool $broken,
    ) {}
}
