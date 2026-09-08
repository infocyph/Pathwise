<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

/**
 * Framework-neutral source for one upload payload.
 *
 * Sources can be borrowed/owned paths, caller-owned streams, or framework
 * mover callbacks such as PSR-style UploadedFile::moveTo(). Pathwise always
 * turns them into a local staging file before UploadProcessor consumes them.
 */
final readonly class UploadSource
{
    /**
     * @param \Closure(string): void $materializer
     */
    private function __construct(
        public string $clientFilename,
        public ?int $size,
        public ?string $clientMediaType,
        public int $error,
        private \Closure $materializer,
    ) {
        if ($clientFilename === '' || str_contains($clientFilename, "\0")) {
            throw new UploadException('Invalid upload client filename.');
        }
        if ($size !== null && $size < 0) {
            throw new UploadException('Invalid upload size metadata.');
        }
    }

    /**
     * Create a source backed by a framework/application mover.
     *
     * The mover receives a Pathwise-owned local target path and should place
     * the payload there before returning.
     *
     * @param callable(string): void $mover
     */
    public static function fromMover(
        callable $mover,
        string $clientFilename,
        ?int $size = null,
        ?string $clientMediaType = null,
        int $error = UPLOAD_ERR_OK,
    ): self {
        return new self(
            $clientFilename,
            $size,
            $clientMediaType,
            $error,
            $mover(...),
        );
    }

    /**
     * Create a source backed by a Pathwise-readable path.
     *
     * Borrowed paths are copied and remain untouched. Owned paths are consumed
     * after a successful staging copy and are therefore deleted even when later
     * upload validation fails.
     */
    public static function fromPath(
        string $path,
        ?string $clientFilename = null,
        ?int $size = null,
        ?string $clientMediaType = null,
        int $error = UPLOAD_ERR_OK,
        bool $owned = false,
    ): self {
        if ($path === '' || str_contains($path, "\0")) {
            throw new UploadException('Invalid upload source path.');
        }

        $resolvedFilename = $clientFilename ?? basename(str_replace('\\', '/', $path));

        return new self(
            $resolvedFilename,
            $size,
            $clientMediaType,
            $error,
            static function (string $target) use ($path, $owned): void {
                try {
                    FlysystemHelper::copy($path, $target);
                    if ($owned) {
                        FlysystemHelper::delete($path);
                    }
                } catch (\Throwable $exception) {
                    throw new UploadException('Unable to materialize upload path.', 0, $exception);
                }
            },
        );
    }

    /**
     * Create a source backed by a caller-owned readable stream.
     *
     * Pathwise reads from the stream's current position and never closes it.
     *
     * @param resource $stream
     */
    public static function fromStream(
        mixed $stream,
        string $clientFilename,
        ?int $size = null,
        ?string $clientMediaType = null,
        int $error = UPLOAD_ERR_OK,
    ): self {
        if (!is_resource($stream)) {
            throw new UploadException('Upload source stream must be a resource.');
        }

        return new self(
            $clientFilename,
            $size,
            $clientMediaType,
            $error,
            static function (string $target) use ($stream): void {
                self::copyStreamToTarget($stream, $target);
            },
        );
    }

    public function materialize(?string $preferredTempDirectory = null): UploadMaterialization
    {
        $root = self::materializationRoot($preferredTempDirectory);
        $directory = self::allocateStagingDirectory($root);
        $target = PathHelper::join($directory, 'payload');

        try {
            ($this->materializer)($target);
            if (is_link($target) || !is_file($target)) {
                throw new UploadException('Upload source did not produce a regular staging file.');
            }

            clearstatcache(true, $target);
            $size = filesize($target);
            if (!is_int($size)) {
                throw new UploadException('Unable to determine materialized upload size.');
            }

            return new UploadMaterialization(
                path: $target,
                size: $size,
                clientFilename: $this->clientFilename,
                clientMediaType: $this->clientMediaType,
                error: $this->error,
                cleanupDirectory: $directory,
            );
        } catch (\Throwable $exception) {
            self::unlinkSilently($target);
            self::removeDirectorySilently($directory);

            if ($exception instanceof UploadException) {
                throw $exception;
            }

            throw new UploadException('Unable to materialize upload source.', 0, $exception);
        }
    }

    private static function allocateStagingDirectory(string $root): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $directory = PathHelper::join($root, 'pathwise-upload-' . bin2hex(random_bytes(16)));
            if (self::runSilently(static fn(): bool => mkdir($directory, 0700))) {
                return $directory;
            }
        }

        throw new UploadException('Unable to allocate upload staging directory.');
    }

    private static function copyStreamToTarget(mixed $stream, string $target): void
    {
        if (!is_resource($stream)) {
            throw new UploadException('Upload source stream is no longer readable.');
        }

        $output = fopen($target, 'xb');
        if (!is_resource($output)) {
            throw new UploadException('Unable to create upload staging file.');
        }

        try {
            if (stream_copy_to_stream($stream, $output) === false) {
                throw new UploadException('Unable to copy upload source stream.');
            }
        } finally {
            fclose($output);
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new UploadException('Unable to create upload staging directory.');
        }
    }

    private static function materializationRoot(?string $preferred): string
    {
        if ($preferred === null || trim($preferred) === '') {
            return self::systemTempDirectory();
        }

        $normalized = PathHelper::normalize($preferred);
        if (
            PathHelper::hasScheme($normalized)
            || (FlysystemHelper::hasDefaultFilesystem() && !PathHelper::isAbsolute($normalized))
        ) {
            return self::systemTempDirectory();
        }

        $directory = PathHelper::toAbsolutePath($normalized);
        self::ensureDirectory($directory);

        return $directory;
    }

    private static function removeDirectorySilently(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        self::runSilently(static fn(): bool => rmdir($directory));
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

    private static function systemTempDirectory(): string
    {
        $directory = PathHelper::toAbsolutePath(sys_get_temp_dir());
        self::ensureDirectory($directory);

        return $directory;
    }

    private static function unlinkSilently(string $path): void
    {
        if (!is_file($path) && !is_link($path)) {
            return;
        }

        self::runSilently(static fn(): bool => unlink($path));
    }
}
