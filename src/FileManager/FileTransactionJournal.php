<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\TransactionRollbackException;

final class FileTransactionJournal
{
    /** @var list<array{path: string, existed: bool, backup: string|null, mode: int|null, owner: int|null, group: int|null}> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $recordedPaths = [];

    public function __construct(public readonly string $originalPath) {}

    public function commit(): void
    {
        $this->cleanup();
        $this->entries = [];
        $this->recordedPaths = [];
    }

    public function record(string $path): void
    {
        $path = \Infocyph\Pathwise\Utils\PathHelper::normalize($path);
        if (isset($this->recordedPaths[$path])) {
            return;
        }

        $existed = is_file($path);
        $backup = null;
        $mode = null;
        $owner = null;
        $group = null;
        if ($existed) {
            $backup = tempnam(sys_get_temp_dir(), 'pathwise_tx_');
            if ($backup === false || !copy($path, $backup)) {
                if (is_string($backup) && is_file($backup)) {
                    $this->unlinkSilently($backup);
                }

                throw new FileAccessException("Unable to create rollback backup for {$path}.");
            }
            $fileMode = fileperms($path);
            $fileOwner = fileowner($path);
            $fileGroup = filegroup($path);
            $mode = is_int($fileMode) ? $fileMode & 0777 : null;
            $owner = is_int($fileOwner) ? $fileOwner : null;
            $group = is_int($fileGroup) ? $fileGroup : null;
        }

        $this->entries[] = [
            'path' => $path,
            'existed' => $existed,
            'backup' => $backup,
            'mode' => $mode,
            'owner' => $owner,
            'group' => $group,
        ];
        $this->recordedPaths[$path] = true;
    }

    public function rollback(?\Throwable $originalFailure = null): void
    {
        $failures = [];
        for ($index = count($this->entries) - 1; $index >= 0; $index--) {
            try {
                $this->restore($this->entries[$index]);
            } catch (\Throwable $exception) {
                $failures[] = $exception;
            }
        }
        $this->cleanup();
        $this->entries = [];
        $this->recordedPaths = [];

        if ($failures !== []) {
            throw new TransactionRollbackException(
                $originalFailure ?? new FileAccessException('An explicit transaction rollback failed.'),
                $failures,
            );
        }
    }

    private function cleanup(): void
    {
        foreach ($this->entries as $entry) {
            if (is_string($entry['backup']) && is_file($entry['backup'])) {
                $this->unlinkSilently($entry['backup']);
            }
        }
    }

    /**
     * @param array{path: string, existed: bool, backup: string|null, mode: int|null, owner: int|null, group: int|null} $entry
     */
    private function restore(array $entry): void
    {
        if (!$entry['existed']) {
            if (is_file($entry['path']) && !unlink($entry['path'])) {
                throw new FileAccessException("Unable to remove transaction-created file: {$entry['path']}");
            }

            return;
        }
        if (!is_string($entry['backup']) || !is_file($entry['backup'])) {
            throw new FileAccessException("Rollback backup is unavailable for {$entry['path']}.");
        }

        $parent = dirname($entry['path']);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new FileAccessException("Unable to recreate rollback directory: {$parent}");
        }
        if (!copy($entry['backup'], $entry['path'])) {
            throw new FileAccessException("Unable to restore rollback backup for {$entry['path']}.");
        }
        $this->restoreMetadata($entry);
    }

    /**
     * @param array{path: string, existed: bool, backup: string|null, mode: int|null, owner: int|null, group: int|null} $entry
     */
    private function restoreMetadata(array $entry): void
    {
        if (is_int($entry['mode']) && !chmod($entry['path'], $entry['mode'])) {
            throw new FileAccessException("Unable to restore permissions for {$entry['path']}.");
        }
        if (is_int($entry['owner']) && fileowner($entry['path']) !== $entry['owner'] && !chown($entry['path'], $entry['owner'])) {
            throw new FileAccessException("Unable to restore owner for {$entry['path']}.");
        }
        if (is_int($entry['group']) && filegroup($entry['path']) !== $entry['group'] && !chgrp($entry['path'], $entry['group'])) {
            throw new FileAccessException("Unable to restore group for {$entry['path']}.");
        }
    }

    private function unlinkSilently(string $path): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            unlink($path);
        } finally {
            restore_error_handler();
        }
    }
}
