<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\DirectoryManager\Concerns;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Core\SyncComparison;
use Infocyph\Pathwise\Exceptions\DirectoryOperationException;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Results\SyncReport;
use Infocyph\Pathwise\Utils\FlysystemHelper;

/**
 * @phpstan-type StorageEntry array<string, mixed>
 */
trait DirectoryOperationsSyncConcern
{
    private function applyPermissionsSilently(string $path, int $permissions): void
    {
        $this->runSilently(static fn(): bool => chmod($path, $permissions));
    }

    private function assertSourceDirectoryExists(): void
    {
        if (!FlysystemHelper::directoryExists($this->path)) {
            throw new DirectoryOperationException("Source directory does not exist: {$this->path}");
        }
    }

    private function assertZipSourceExists(string $source): void
    {
        if (!FlysystemHelper::fileExists($source)) {
            throw new DirectoryOperationException("ZIP source does not exist: {$source}");
        }
    }

    private function attemptNativeCopy(string $destination, ?callable $progress): bool
    {
        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            if (!$this->isLocalPath($this->path) || !$this->isLocalPath($destination)) {
                throw new UnsupportedStorageOperationException('Native directory copy requires local source and destination paths.');
            }
            if (!NativeOperationsAdapter::canUseNativeDirectoryCopy()) {
                throw new NativeExecutionException('Native directory copy executable is unavailable.');
            }
        }

        if (!$this->canAttemptNativeCopy($destination)) {
            return false;
        }

        $this->emitCopyProgress($progress, 0);
        $native = NativeOperationsAdapter::copyDirectory($this->path, $destination, false);
        if ($native->success) {
            $this->emitCopyProgress($progress, 1);

            return true;
        }

        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            throw new NativeExecutionException(
                "Native directory copy failed with exit code {$native->exitCode}: " . implode("\n", $native->output),
                $native,
            );
        }

        return false;
    }

    private function canAttemptNativeCopy(string $destination): bool
    {
        return $this->executionStrategy !== ExecutionStrategy::PHP
            && NativeOperationsAdapter::canUseNativeDirectoryCopy()
            && $this->isLocalPath($this->path)
            && $this->isLocalPath($destination);
    }

    private function checksumsMatch(string $sourcePath, string $targetPath): bool
    {
        $sourceHash = FlysystemHelper::checksum($sourcePath, 'sha256');
        $targetHash = FlysystemHelper::checksum($targetPath, 'sha256');

        return is_string($sourceHash) && is_string($targetHash) && hash_equals($sourceHash, $targetHash);
    }

    private function cleanupTemporaryFile(bool $shouldCleanup, string $path): void
    {
        if ($shouldCleanup && is_file($path)) {
            $this->unlinkFileSilently($path);
        }
    }

    private function copyIfSyncRequired(
        string $sourcePath,
        string $targetPath,
        SyncComparison $comparison,
    ): string {
        if (!FlysystemHelper::fileExists($targetPath)) {
            FlysystemHelper::copy($sourcePath, $targetPath);

            return 'created';
        }

        if (!$this->filesMatchForSync($sourcePath, $targetPath, $comparison)) {
            FlysystemHelper::copy($sourcePath, $targetPath);

            return 'updated';
        }

        return 'unchanged';
    }

    private function createDirectorySilently(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        $this->runSilently(static fn(): bool => mkdir($path, 0755, true));
    }

    /**
     * @param array<string, string> $sourceEntries
     * @param array<string, list<string>> $report
     */
    private function deleteSyncOrphans(string $destination, array $sourceEntries, array &$report): void
    {
        $destinationLocation = $this->storageLocation($destination);
        $destinationItems = iterator_to_array($this->listStorageEntries($destination, true), false);

        usort(
            $destinationItems,
            fn(array $a, array $b): int => strlen($this->entryPath($b)) <=> strlen($this->entryPath($a)),
        );

        foreach ($destinationItems as $item) {
            $relative = $this->relativeStoragePath($destinationLocation, $this->entryPath($item));
            if ($relative === '' || isset($sourceEntries[$relative])) {
                continue;
            }

            $targetPath = $this->buildPath($destination, $relative);
            if ($this->entryType($item) === 'dir') {
                FlysystemHelper::deleteDirectory($targetPath);
                $report['deleted'][] = $relative . '/';

                continue;
            }

            FlysystemHelper::delete($targetPath);
            $report['deleted'][] = $relative;
        }
    }

    private function emitCopyProgress(?callable $progress, int $current): void
    {
        if (!is_callable($progress)) {
            return;
        }

        $progress([
            'operation' => 'copy',
            'path' => $this->path,
            'current' => $current,
            'total' => 1,
        ]);
    }

    private function emitSyncProgress(?callable $progress, string $relative, int $current, ?int $total): void
    {
        if (!is_callable($progress)) {
            return;
        }

        $progress([
            'operation' => 'sync',
            'path' => $relative,
            'current' => $current,
            'total' => $total,
        ]);
    }

    private function filesMatchForSync(
        string $sourcePath,
        string $targetPath,
        SyncComparison $comparison,
    ): bool {
        return match ($comparison) {
            SyncComparison::ALWAYS_COPY => false,
            SyncComparison::SIZE => FlysystemHelper::size($sourcePath) === FlysystemHelper::size($targetPath),
            SyncComparison::SIZE_AND_MODIFIED_TIME => FlysystemHelper::size($sourcePath) === FlysystemHelper::size($targetPath)
                && (
                    FlysystemHelper::lastModified($sourcePath) === FlysystemHelper::lastModified($targetPath)
                    || $this->checksumsMatch($sourcePath, $targetPath)
                ),
            SyncComparison::CHECKSUM => $this->checksumsMatch($sourcePath, $targetPath),
        };
    }

    /**
     * @param array<string, list<string>> $report
     */
    private function finalizeSyncReport(array $report): SyncReport
    {
        return new SyncReport(
            created: $report['created'] ?? [],
            updated: $report['updated'] ?? [],
            deleted: $report['deleted'] ?? [],
            unchanged: $report['unchanged'] ?? [],
        );
    }

    /** @return array{created: list<string>, updated: list<string>, deleted: list<string>, unchanged: list<string>} */
    private function newSyncReport(): array
    {
        return [
            'created' => [],
            'updated' => [],
            'deleted' => [],
            'unchanged' => [],
        ];
    }

    private function parentDirectoryExists(string $path): bool
    {
        $parent = dirname($path);
        if ($parent === '' || $parent === '.' || $parent === $path) {
            return true;
        }

        return is_dir($parent) || FlysystemHelper::directoryExists($parent);
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

    /**
     * @param StorageEntry $item
     * @param array<string, string> $sourceEntries
     * @param array<string, list<string>> $report
     */
    private function syncOneItem(
        string $destination,
        string $relative,
        array $item,
        array &$sourceEntries,
        array &$report,
        SyncComparison $comparison,
    ): void {
        $type = $this->entryType($item);
        $sourceEntries[$relative] = $type;

        if ($type === 'dir') {
            $targetPath = $this->buildPath($destination, $relative);
            if (!FlysystemHelper::directoryExists($targetPath)) {
                FlysystemHelper::createDirectory($targetPath);
                $report['created'][] = $relative . '/';
            }

            return;
        }

        $sourcePath = $this->buildPath($this->path, $relative);
        $targetPath = $this->buildPath($destination, $relative);
        $result = $this->copyIfSyncRequired($sourcePath, $targetPath, $comparison);
        $report[$result][] = $relative;
    }

    private function unlinkFileSilently(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $this->runSilently(static fn(): bool => unlink($path));
    }
}
