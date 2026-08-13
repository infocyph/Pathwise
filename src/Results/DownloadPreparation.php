<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

use InvalidArgumentException;

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
    ) {
        if ($size < 0 || $lastModified < 0) {
            throw new InvalidArgumentException('Download sizes and timestamps must be non-negative.');
        }
        if (!in_array($status, [200, 206], true)) {
            throw new InvalidArgumentException('Download status must be 200 or 206.');
        }
        if (($status === 206) !== $range->partial) {
            throw new InvalidArgumentException('Download status does not match range metadata.');
        }
    }
}
