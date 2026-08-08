<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

interface AuditSink
{
    /** @param array<string, mixed> $record */
    public function write(array $record): void;
}
