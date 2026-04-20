<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager\Concerns;

use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use ZipArchive;

/**
 * @phpstan-type HookCallback callable(mixed...): mixed
 */
trait FileCompressionRuntimeConcern
{
    private function cleanupDeferredLocalizedPaths(): void
    {
        if ($this->localizedCleanupPaths === []) {
            return;
        }

        foreach (array_keys($this->localizedCleanupPaths) as $path) {
            $this->cleanupLocalizedPath($path);
        }

        $this->localizedCleanupPaths = [];
    }

    private function cleanupLocalizedPath(?string $path): void
    {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            $this->unlinkPathSilently($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                $this->removeDirectorySilently($item->getPathname());
            } else {
                $this->unlinkPathSilently($item->getPathname());
            }
        }

        $this->removeDirectorySilently($path);
    }

    /**
     * Closes the current ZIP archive.
     *
     * If the archive is currently open, this method will close it using the
     * ZipArchive::close() method. The $isOpen flag is then set to false to
     * indicate that the archive is no longer open.
     */
    private function closeZip(): void
    {
        if ($this->isOpen) {
            $this->zip->close();
            $this->isOpen = false;
            $this->syncWorkingZipIfNeeded();
        }

        $this->cleanupDeferredLocalizedPaths();
    }

    private function deferLocalizedCleanupPath(?string $path): void
    {
        if (!is_string($path) || $path === '') {
            return;
        }

        $this->localizedCleanupPaths[$path] = true;
    }

    private function loadIgnorePatterns(string $source): void
    {
        $this->doLoadIgnorePatterns($source);
    }

    private function localizeCompressionSource(string $source, ?string &$cleanupPath = null): string
    {
        return $this->doLocalizeCompressionSource($source, $cleanupPath);
    }

    /**
     * Logs a message using the registered logger callback.
     *
     * If a logger function is set, this method will invoke it
     * with the provided message.
     *
     * @param string $message The message to log.
     */
    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($message);
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeNonEmptyStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * Opens the ZIP archive with the specified flags.
     *
     * This method attempts to open the ZIP archive located at the specified
     * file path using the provided flags. If the archive cannot be opened,
     * an exception is thrown with the corresponding error code.
     *
     * @param int $flags Optional flags to use when opening the ZIP archive.
     * @throws CompressionException if the ZIP archive cannot be opened.
     */
    private function openZip(int $flags = 0): void
    {
        if (($flags & ZipArchive::CREATE) === 0 && !FlysystemHelper::fileExists($this->zipFilePath)) {
            throw new CompressionException("ZIP archive does not exist: {$this->zipFilePath}");
        }

        $result = $this->zip->open($this->workingZipPath, $flags);
        if ($result !== true) {
            throw new CompressionException("Failed to open ZIP archive at {$this->zipFilePath}. Error: $result");
        }
        $this->isOpen = true;
    }

    private function removeDirectorySilently(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $this->runSilently(static fn(): bool => rmdir($path));
    }

    /**
     * Reopen the ZIP archive if it has been closed.
     *
     * If the archive is already open, this method is a no-op.
     * @throws CompressionException
     */
    private function reopenIfNeeded(): void
    {
        if (!$this->isOpen) {
            $this->openZip();
        }
    }

    private function resolveWorkingZipPath(bool $create): string
    {
        return $this->doResolveWorkingZipPath($create);
    }

    private function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function shouldIncludePath(string $relativePath): bool
    {
        return $this->doShouldIncludePath($relativePath);
    }

    private function shouldTraverseDirectory(string $relativePath): bool
    {
        return $this->doShouldTraverseDirectory($relativePath);
    }

    private function syncWorkingZipIfNeeded(): void
    {
        $this->doSyncWorkingZipIfNeeded();
    }

    /**
     * Triggers all registered hooks for a specified event.
     *
     * This method iterates over the registered callbacks for the given event
     * and executes each callback with the provided arguments. If no hooks are
     * registered for the event, the method does nothing.
     *
     * @param string $event The name of the event to trigger hooks for.
     * @param mixed ...$args Arguments to pass to the callback functions.
     */
    private function triggerHook(string $event, mixed ...$args): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            $callback(...$args);
        }
    }

    private function unlinkPathSilently(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $this->runSilently(static fn(): bool => unlink($path));
    }
}
