<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class SyncReport
{
    /**
     * @param list<string> $created
     * @param list<string> $updated
     * @param list<string> $deleted
     * @param list<string> $unchanged
     */
    public function __construct(
        public array $created,
        public array $updated,
        public array $deleted,
        public array $unchanged,
    ) {}
}
