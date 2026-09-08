<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Security;

/** @internal */
final readonly class ZipArchiveManifestEntry
{
    public function __construct(
        public int $index,
        public string $archiveName,
        public string $path,
        public int $uncompressedBytes,
        public bool $directory,
    ) {}

    public function withPath(string $path): self
    {
        return new self(
            index: $this->index,
            archiveName: $this->archiveName,
            path: $path,
            uncompressedBytes: $this->uncompressedBytes,
            directory: $this->directory,
        );
    }
}
