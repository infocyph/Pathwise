<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class ChunkUploadState
{
    public function __construct(
        public string $uploadId,
        public int $receivedChunks,
        public int $totalChunks,
        public bool $complete,
    ) {}
}
