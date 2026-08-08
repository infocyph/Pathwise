<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Observability;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

final readonly class PartitionedAuditSink implements AuditSink
{
    public function __construct(private string $directory) {}

    public function write(array $record): void
    {
        $partition = gmdate('Y/m/d/H');
        $name = sprintf('%s-%s.json', gmdate('Ymd\THis.u\Z'), bin2hex(random_bytes(12)));
        $path = PathHelper::join($this->directory, $partition, $name);
        FlysystemHelper::write($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
