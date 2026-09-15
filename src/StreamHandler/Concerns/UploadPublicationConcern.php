<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Concerns;

use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\Security\LocalPathContainment;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

trait UploadPublicationConcern
{
    private function assertLocalPublicationTarget(string $destination): string
    {
        $root = $this->storageDirectLocalPath($this->uploadDir);
        $target = $this->storageDirectLocalPath($destination);
        if (
            $root === null
            || $target === null
            || !LocalPathContainment::isSameOrDescendant($root, $target)
            || !LocalPathContainment::isSameOrDescendant($root, dirname($target))
        ) {
            throw new UploadException('Upload publication target escaped the configured root.');
        }
        if (is_link($target)) {
            throw new UploadException('Upload publication target cannot be a symbolic link.');
        }

        return $target;
    }

    private function finalizeLocalPermissions(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new UploadException('Published upload is not a regular file.');
        }

        $mode = $this->publishedFileMode();
        if ($mode !== null && !$this->runSilently(static fn(): bool => chmod($path, $mode))) {
            throw new UploadException('Unable to apply restrictive upload permissions.');
        }
    }

    private function moveStoragePath(string $source, string $destination): void
    {
        $sourceResolution = $this->storageResolution($source);
        $destinationResolution = $this->storageResolution($destination);
        if ($sourceResolution === null && $destinationResolution === null) {
            FlysystemHelper::move($source, $destination);

            return;
        }
        if (
            $sourceResolution === null
            || $destinationResolution === null
            || $sourceResolution[0] !== $destinationResolution[0]
        ) {
            throw new UploadException('Controlled publication requires one destination filesystem.');
        }

        $sourceResolution[0]->move($sourceResolution[1], $destinationResolution[1]);
    }

    private function publishAdapterUpload(string $source, string $destination): void
    {
        $temporary = $destination . '.pathwise-upload-' . bin2hex(random_bytes(16)) . '.tmp';
        $sourceSize = $this->storageSize($source);

        try {
            if ($this->storageFileExists($destination) || $this->storageFileExists($temporary)) {
                throw new UploadException('Upload publication target already exists.');
            }

            $this->storageCopy($source, $temporary);
            if ($this->storageSize($temporary) !== $sourceSize || $this->storageSize($source) !== $sourceSize) {
                throw new UploadException('Upload changed during controlled publication.');
            }

            $sourceChecksum = $this->storageChecksum($source, 'sha256');
            $temporaryChecksum = $this->storageChecksum($temporary, 'sha256');
            if (
                is_string($sourceChecksum)
                && is_string($temporaryChecksum)
                && !hash_equals($sourceChecksum, $temporaryChecksum)
            ) {
                throw new UploadException('Upload changed during controlled publication.');
            }
            if ($this->storageFileExists($destination)) {
                throw new UploadException('Upload publication target already exists.');
            }

            $this->moveStoragePath($temporary, $destination);
            $this->deleteIncomingFile($source);
        } catch (\Throwable $exception) {
            if ($this->storageFileExists($temporary)) {
                $this->storageDelete($temporary);
            }

            if ($exception instanceof UploadException) {
                throw $exception;
            }

            throw new UploadException('Failed to publish incoming file.', 0, $exception);
        }
    }

    private function publishIncomingFile(string $source, string $destination): void
    {
        $directDestination = $this->storageDirectLocalPath($destination);
        $directRoot = $this->storageDirectLocalPath($this->uploadDir);
        if ($directDestination !== null && $directRoot !== null) {
            $this->publishLocalUpload($source, $destination);

            return;
        }

        $this->publishAdapterUpload($source, $destination);
    }

    private function publishLocalUpload(string $source, string $destination): void
    {
        $target = $this->assertLocalPublicationTarget($destination);
        if (file_exists($target) || is_link($target)) {
            throw new UploadException('Upload publication target already exists.');
        }

        $directSource = $this->storageDirectLocalPath($source);
        if (
            $directSource !== null
            && is_file($directSource)
            && !is_link($directSource)
            && $this->runSilently(static fn(): bool => rename($directSource, $target))
        ) {
            $this->finalizeLocalPermissions($target);

            return;
        }

        $sourceSize = $this->storageSize($source);
        $temporary = PathHelper::join(dirname($target), '.pathwise-upload-' . bin2hex(random_bytes(16)) . '.tmp');
        $input = $this->storageReadStream($source);
        $output = fopen($temporary, 'xb');
        if (!is_resource($output)) {
            fclose($input);
            throw new UploadException('Unable to create destination-side upload staging file.');
        }

        try {
            if (!$this->runSilently(static fn(): bool => chmod($temporary, 0600))) {
                throw new UploadException('Unable to secure destination-side upload staging file.');
            }
            $copied = stream_copy_to_stream($input, $output);
            if (!is_int($copied) || $copied !== $sourceSize) {
                throw new UploadException('Upload changed during controlled publication.');
            }
            if (!fflush($output)) {
                throw new UploadException('Unable to flush destination-side upload staging file.');
            }
            if (function_exists('fsync') && !fsync($output)) {
                throw new UploadException('Unable to sync destination-side upload staging file.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        try {
            clearstatcache(true, $temporary);
            if (
                is_link($temporary)
                || !is_file($temporary)
                || filesize($temporary) !== $sourceSize
                || $this->storageSize($source) !== $sourceSize
            ) {
                throw new UploadException('Upload changed during controlled publication.');
            }

            $target = $this->assertLocalPublicationTarget($destination);
            if (file_exists($target) || is_link($target)) {
                throw new UploadException('Upload publication target already exists.');
            }
            if (!$this->runSilently(static fn(): bool => rename($temporary, $target))) {
                throw new UploadException('Unable to atomically publish destination-side upload staging file.');
            }

            $this->finalizeLocalPermissions($target);
            $this->deleteIncomingFile($source);
        } finally {
            if (is_file($temporary) || is_link($temporary)) {
                $this->runSilently(static fn(): bool => unlink($temporary));
            }
        }
    }

    private function securePublishedDirectory(string $path): void
    {
        $mode = $this->publishedDirectoryMode();
        if ($mode === null) {
            return;
        }

        $directPath = $this->storageDirectLocalPath($path);
        $root = $this->storageDirectLocalPath($this->uploadDir);
        if ($directPath === null || $root === null) {
            return;
        }
        if (!LocalPathContainment::isSameOrDescendant($root, $directPath)) {
            throw new UploadException('Upload directory escaped the configured root.');
        }
        if (is_link($directPath) || !is_dir($directPath)) {
            throw new UploadException('Upload directory is not a regular directory.');
        }
        if (!$this->runSilently(static fn(): bool => chmod($directPath, $mode))) {
            throw new UploadException('Unable to apply restrictive upload directory permissions.');
        }
    }

    private function secureUploadStagingDirectory(string $path): void
    {
        if (!$this->isStrictUntrustedProfile()) {
            return;
        }

        $directPath = $this->storageDirectLocalPath($path);
        if ($directPath !== null && !$this->runSilently(static fn(): bool => chmod($directPath, 0700))) {
            throw new UploadException('Unable to secure upload staging directory.');
        }
    }

    private function secureUploadStagingFile(string $path): void
    {
        if (!$this->isStrictUntrustedProfile()) {
            return;
        }

        $directPath = $this->storageDirectLocalPath($path);
        if ($directPath !== null && !$this->runSilently(static fn(): bool => chmod($directPath, 0600))) {
            throw new UploadException('Unable to secure upload staging file.');
        }
    }
}
