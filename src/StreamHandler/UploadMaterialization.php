<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

use Infocyph\Pathwise\Exceptions\UploadException;

/**
 * A local, Pathwise-owned upload staging file.
 *
 * Callers should release the materialization in a finally block. Cleanup is
 * idempotent so it is also safe after UploadProcessor has moved the file to its
 * final destination.
 */
final readonly class UploadMaterialization
{
    public function __construct(
        public string $path,
        public int $size,
        public string $clientFilename,
        public ?string $clientMediaType = null,
        public int $error = UPLOAD_ERR_OK,
    ) {
        if ($path === '' || str_contains($path, "\0")) {
            throw new UploadException('Invalid materialized upload path.');
        }
        if ($size < 0) {
            throw new UploadException('Invalid materialized upload size.');
        }
        if ($clientFilename === '' || str_contains($clientFilename, "\0")) {
            throw new UploadException('Invalid upload client filename.');
        }
    }

    public function cleanup(): void
    {
        if (!is_file($this->path) && !is_link($this->path)) {
            return;
        }

        set_error_handler(static fn(): bool => true);

        try {
            unlink($this->path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{
     *   error: int,
     *   size: int,
     *   tmp_name: string,
     *   name: string,
     *   type: string|null
     * }
     */
    public function toFileArray(): array
    {
        return [
            'error' => $this->error,
            'size' => $this->size,
            'tmp_name' => $this->path,
            'name' => $this->clientFilename,
            'type' => $this->clientMediaType,
        ];
    }
}
