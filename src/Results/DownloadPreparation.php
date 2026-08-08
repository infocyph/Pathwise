<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class DownloadPreparation
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $path,
        public string $fileName,
        public string $mimeType,
        public int $size,
        public int $lastModified,
        public string $etag,
        public int $status,
        public RangeDownloadMetadata $range,
        public array $headers,
    ) {}
}
