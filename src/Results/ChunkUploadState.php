<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

use InvalidArgumentException;

final readonly class ChunkUploadState
{
    public function __construct(
        public string $uploadId,
        public int $receivedChunks,
        public int $totalChunks,
        public bool $complete,
    ) {
        if ($uploadId === '' || $receivedChunks < 0 || $totalChunks < 1 || $receivedChunks > $totalChunks) {
            throw new InvalidArgumentException('Invalid chunk upload state.');
        }
        if ($complete !== ($receivedChunks === $totalChunks)) {
            throw new InvalidArgumentException('Chunk completion flag does not match the chunk counts.');
        }
    }
}
