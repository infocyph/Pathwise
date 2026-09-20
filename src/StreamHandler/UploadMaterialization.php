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
        public string $sha256 = '',
        private ?string $cleanupDirectory = null,
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
        if ($sha256 === '' || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new UploadException('Invalid materialized upload digest.');
        }
        if (
            $cleanupDirectory !== null
            && ($cleanupDirectory === '' || str_contains($cleanupDirectory, "\0"))
        ) {
            throw new UploadException('Invalid upload staging directory.');
        }
    }

    public function assertUnchanged(): void
    {
        clearstatcache(true, $this->path);
        if (is_link($this->path) || !is_file($this->path)) {
            throw new UploadException('Materialized upload changed before publication.');
        }

        $size = filesize($this->path);
        $digest = hash_file('sha256', $this->path);
        if (!is_int($size) || !is_string($digest) || $size !== $this->size || !hash_equals($this->sha256, $digest)) {
            throw new UploadException('Materialized upload changed before publication.');
        }
    }

    public function cleanup(): void
    {
        self::unlinkSilently($this->path);

        $directory = $this->cleanupDirectory;
        if ($directory !== null && is_dir($directory) && !is_link($directory)) {
            self::runSilently(static fn(): bool => rmdir($directory));
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

    private static function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private static function unlinkSilently(string $path): void
    {
        if (!is_file($path) && !is_link($path)) {
            return;
        }

        self::runSilently(static fn(): bool => unlink($path));
    }
}
