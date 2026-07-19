<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

use DateTimeInterface;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use RuntimeException;

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

        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if ($this->isLocalLogPath()) {
            $written = file_put_contents($this->logFilePath, $line, FILE_APPEND | LOCK_EX);
            if ($written !== strlen($line)) {
                throw new RuntimeException("Unable to append audit record to {$this->logFilePath}.");
            }

            return;
        }

        $existing = FlysystemHelper::fileExists($this->logFilePath) ? FlysystemHelper::read($this->logFilePath) : '';
        FlysystemHelper::write($this->logFilePath, $existing . $line);
    }

    private function isLocalLogPath(): bool
    {
        return !PathHelper::hasScheme($this->logFilePath)
            && (PathHelper::isAbsolute($this->logFilePath) || !FlysystemHelper::hasDefaultFilesystem());
    }
}
