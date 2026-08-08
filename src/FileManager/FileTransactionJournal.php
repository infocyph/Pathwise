<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Exceptions\FileAccessException;

final class FileTransactionJournal
{
    /** @var list<array{path: string, existed: bool, backup: string|null, permissions: int|null}> */
    private array $entries = [];

    public function __construct(public readonly string $originalPath) {}

    public function commit(): void
    {
        $this->cleanup();
        $this->entries = [];
    }

    public function record(string $path): void
    {
        $existed = is_file($path);
        $backup = null;
        $permissions = null;
        if ($existed) {
            $backup = tempnam(sys_get_temp_dir(), 'pathwise_tx_');
            if ($backup === false || !copy($path, $backup)) {
                throw new FileAccessException("Unable to create rollback backup for {$path}.");
            }
            $mode = fileperms($path);
            $permissions = is_int($mode) ? $mode & 0777 : null;
        }

        $this->entries[] = [
            'path' => $path,
            'existed' => $existed,
            'backup' => $backup,
            'permissions' => $permissions,
        ];
    }

    public function rollback(): void
    {
        $failures = [];
        for ($index = count($this->entries) - 1; $index >= 0; $index--) {
            try {
                $this->restore($this->entries[$index]);
            } catch (\Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }
        $this->cleanup();
        $this->entries = [];

        if ($failures !== []) {
            throw new FileAccessException('Transaction rollback failed: ' . implode('; ', $failures));
        }
    }

    private function cleanup(): void
    {
        foreach ($this->entries as $entry) {
            if (is_string($entry['backup']) && is_file($entry['backup'])) {
                unlink($entry['backup']);
            }
        }
    }

    /**
     * @param array{path: string, existed: bool, backup: string|null, permissions: int|null} $entry
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
        if (is_int($entry['permissions']) && !chmod($entry['path'], $entry['permissions'])) {
            throw new FileAccessException("Unable to restore permissions for {$entry['path']}.");
        }
    }
}
