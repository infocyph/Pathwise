<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

use DateTimeImmutable;
use DateTimeZone;
use Infocyph\Pathwise\Exceptions\AuditException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

final readonly class PartitionedAuditSink implements AuditSink
{
    public function __construct(private string $directory) {}

    public function write(array $record): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $partition = $now->format('Y/m/d/H');
        $name = sprintf('%s-%s.json', $now->format('Ymd\THis.u\Z'), bin2hex(random_bytes(12)));
        $path = PathHelper::join($this->directory, $partition, $name);

        try {
            FlysystemHelper::write($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $exception) {
            throw new AuditException("Unable to write partitioned audit record: {$path}", 0, $exception);
        }
    }
}
