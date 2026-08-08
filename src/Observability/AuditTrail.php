<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

use DateTimeInterface;

final readonly class AuditTrail
{
    private AuditSink $sink;

    public function __construct(string|AuditSink $sink)
    {
        $this->sink = is_string($sink) ? new LocalJsonlAuditSink($sink) : $sink;
    }

    /**
     * Get the log file path.
     *
     * @return string The normalized log file path.
     */
    public function getLogFilePath(): ?string
    {
        return $this->sink instanceof LocalJsonlAuditSink ? $this->sink->path : null;
    }

    /**
     * Log an operation with context.
     *
     * @param string $operation The operation name.
     * @param array<string, mixed> $context Additional context data.
     */
    public function log(string $operation, array $context = []): void
    {
        $record = [
            'timestamp' => date(DateTimeInterface::ATOM),
            'operation' => $operation,
            'context' => $context,
        ];

        $this->sink->write($record);
    }
}
