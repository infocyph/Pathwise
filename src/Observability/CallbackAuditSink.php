<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

final readonly class CallbackAuditSink implements AuditSink
{
    /** @var \Closure(array<string, mixed>): void */
    private \Closure $callback;

    /** @param callable(array<string, mixed>): void $callback */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function write(array $record): void
    {
        ($this->callback)($record);
    }
}
