<?php

namespace Infocyph\Pathwise\Observability;

use DateTimeInterface;
use Infocyph\Pathwise\Utils\PathHelper;

final class AuditTrail
{
    public function __construct(private readonly string $logFilePath)
    {
        $directory = dirname($this->logFilePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    public function getLogFilePath(): string
    {
        return PathHelper::normalize($this->logFilePath);
    }

    public function log(string $operation, array $context = []): void
    {
        $record = [
            'timestamp' => date(DateTimeInterface::ATOM),
            'operation' => $operation,
            'context' => $context,
        ];

        file_put_contents($this->logFilePath, json_encode($record) . PHP_EOL, FILE_APPEND);
    }
}
