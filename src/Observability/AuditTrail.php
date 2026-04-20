<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

use DateTimeInterface;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

final readonly class AuditTrail
{
    public function __construct(private string $logFilePath)
    {
        $directory = dirname($this->logFilePath);
        if (!FlysystemHelper::directoryExists($directory)) {
            FlysystemHelper::createDirectory($directory);
        }
    }

    /**
     * Get the log file path.
     *
     * @return string The normalized log file path.
     */
    public function getLogFilePath(): string
    {
        return PathHelper::normalize($this->logFilePath);
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

        $existing = FlysystemHelper::fileExists($this->logFilePath)
            ? FlysystemHelper::read($this->logFilePath)
            : '';

        FlysystemHelper::write($this->logFilePath, $existing . json_encode($record) . PHP_EOL);
    }
}
