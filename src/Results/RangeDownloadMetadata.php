<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

use InvalidArgumentException;

final readonly class RangeDownloadMetadata
{
    public function __construct(
        public ?int $start,
        public ?int $end,
        public int $contentLength,
        public bool $partial,
    ) {
        if ($contentLength < 0) {
            throw new InvalidArgumentException('Range content length must be non-negative.');
        }
        if ($start === null || $end === null) {
            if ($start !== null || $end !== null || $contentLength !== 0 || $partial) {
                throw new InvalidArgumentException('Empty range metadata is inconsistent.');
            }

            return;
        }
        if ($start < 0 || $end < $start || $contentLength !== ($end - $start) + 1) {
            throw new InvalidArgumentException('Range ordering or content length is invalid.');
        }
    }
}
