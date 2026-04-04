<?php

namespace Infocyph\Pathwise\Observability;

use DateTimeInterface;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

final class AuditTrail
{
    public function __construct(private readonly string $logFilePath)
    {
        $directory = dirname($this->logFilePath);
        if (!FlysystemHelper::directoryExists($directory)) {
            FlysystemHelper::createDirectory($directory);
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

        $existing = FlysystemHelper::fileExists($this->logFilePath)
            ? FlysystemHelper::read($this->logFilePath)
            : '';

        FlysystemHelper::write($this->logFilePath, $existing . json_encode($record) . PHP_EOL);
    }
}
