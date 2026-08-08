<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class DownloadStreamResult
{
    public function __construct(public DownloadPreparation $preparation, public int $bytesSent) {}
}
