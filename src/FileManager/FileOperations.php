<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use DateTimeInterface;
use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Exceptions\TransactionStateException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Security\PolicyEngine;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use SplFileInfo;
use SplFileObject;

class FileOperations
{
    protected ?SplFileObject $file = null;

    private ?AuditTrail $auditTrail = null;

    private ExecutionStrategy $executionStrategy = ExecutionStrategy::AUTO;

    private ?PolicyEngine $policyEngine = null;

    private bool $transactionActive = false;

    private ?FileTransactionJournal $transactionJournal = null;

    /**
     * Constructor to initialize the file path.
     */
    public function __construct(protected string $filePath)
    {
        $this->filePath = PathHelper::normalize($filePath);
    }

    /**
     * Append content to the file.
     */
    public function append(string $content, bool $lock = true): self
    {
        $this->assertPolicy('append', $this->filePath);
        $this->assertLocalOperation('append');
        $this->recordFileState($this->filePath);

        $flags = FILE_APPEND | ($lock ? LOCK_EX : 0);
        if (file_put_contents($this->filePath, $content, $flags) === false) {
            throw new FileAccessException("Unable to append to file: {$this->filePath}");
        }
        $this->audit('append', ['path' => $this->filePath, 'bytes' => strlen($content)]);

        return $this;
    }

    /**
     * Append to adapter-backed storage by explicitly replacing the complete object.
     */
    public function appendEmulated(string $content): self
    {
        $this->assertPolicy('append', $this->filePath);
        if (FlysystemHelper::isLocalPath($this->filePath)) {
            return $this->append($content);
        }

        $existing = $this->exists() ? FlysystemHelper::read($this->filePath) : '';
        FlysystemHelper::write($this->filePath, $existing . $content);
        $this->audit('append-emulated', ['path' => $this->filePath, 'bytes' => strlen($content)]);

        return $this;
    }

    /**
     * Begin a transaction for atomic file operations.
     *
     * @return self This instance for method chaining.
     */
    public function beginTransaction(): self
    {
        if ($this->transactionActive) {
            throw new TransactionStateException('Nested transactions are not supported.');
        }
        $this->assertLocalOperation('transactions');
        $this->transactionActive = true;
        $this->transactionJournal = new FileTransactionJournal($this->filePath);

        return $this;
    }

    /**
     * Commit the current transaction.
     *
     * @return self This instance for method chaining.
     */
    public function commitTransaction(): self
    {
        $this->assertTransactionActive('commit');
        $this->transactionJournal?->commit();
        $this->transactionActive = false;
        $this->transactionJournal = null;

        return $this;
    }

    /**
     * Copy the file to a new location.
     */
    public function copy(string $destination, ?callable $progress = null): self
    {
        $this->assertPolicy('copy', $this->filePath, ['destination' => $destination]);

        $this->emitCopyProgress($progress, $destination, 0);
        $this->recordFileState($destination);
        $this->performCopy($destination);
        $this->emitCopyProgress($progress, $destination, 1);
        $this->audit('copy', ['source' => $this->filePath, 'destination' => $destination]);

        return $this;
    }

    /**
     * Copy file and verify integrity using checksum.
     */
    public function copyWithVerification(string $destination, string $algorithm = 'sha256'): self
    {
        $this->copy($destination);

        if (!in_array($algorithm, hash_algos(), true)) {
            throw new FileAccessException("Unsupported checksum algorithm: {$algorithm}");
        }

        $sourceHash = FlysystemHelper::checksum($this->filePath, $algorithm);
        $destinationHash = FlysystemHelper::checksum($destination, $algorithm);
        if (!is_string($sourceHash) || !is_string($destinationHash) || !hash_equals($sourceHash, $destinationHash)) {
            throw new FileAccessException("Checksum verification failed after copying to {$destination}.");
        }

        return $this;
    }

    /**
     * Create or overwrite the file with optional content.
     */
    public function create(?string $content = ''): self
    {
        $this->assertPolicy('create', $this->filePath);
        $this->recordFileState($this->filePath);
        FlysystemHelper::write($this->filePath, (string) $content);
        $this->audit('create', ['path' => $this->filePath]);

        return $this;
    }

    /**
     * Delete the file.
     */
    public function delete(): self
    {
        $this->assertPolicy('delete', $this->filePath);
        if (!$this->exists()) {
            throw new FileNotFoundException("File does not exist at $this->filePath.");
        }
        $this->recordFileState($this->filePath);

        try {
            FlysystemHelper::delete($this->filePath);
        } catch (\Throwable $e) {
            throw new FileAccessException("Unable to delete file at $this->filePath.", 0, $e);
        }
        $this->audit('delete', ['path' => $this->filePath]);

        return $this;
    }

    /**
     * Check if a file exists at the given path.
     */
    public function exists(): bool
    {
        return FlysystemHelper::fileExists($this->filePath);
    }

    /**
     * Get the line count of the file using SplFileObject.
     */
    public function getLineCount(): int
    {
        $file = $this->requireFile();
        $file->seek(PHP_INT_MAX);

        return $file->key() + 1;
    }

    /**
     * Get all metadata for the file.
     *
     * @return array{
     *     permissions: string,
     *     size: int,
     *     last_modified: int,
     *     owner: int|false,
     *     group: int|false,
     *     type: string|false,
     *     mime_type: string|null,
     *     extension: string
     * }
     */
    public function getMetadata(): array
    {
        $this->assertLocalOperation('local metadata');
        $info = new SplFileInfo($this->filePath);

        return [
            'permissions' => substr(sprintf('%o', $info->getPerms()), -4),
            'size' => FlysystemHelper::size($this->filePath),
            'last_modified' => FlysystemHelper::lastModified($this->filePath),
            'owner' => $info->getOwner(),
            'group' => $info->getGroup(),
            'type' => $info->getType(),
            'mime_type' => $this->determineMimeType(),
            'extension' => $info->getExtension(),
        ];
    }

    /**
     * Check if a file is readable.
     *
     * @throws FileNotFoundException
     */
    public function isReadable(): bool
    {
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }

        if (!FlysystemHelper::isLocalPath($this->filePath)) {
            return true;
        }

        return is_readable($this->filePath);
    }

    /**
     * Open the file with a lock, optionally with a timeout.
     *
     * @throws FileAccessException
     */
    public function openWithLock(bool $exclusive = true, int $timeout = 0): self
    {
        $this->assertLocalOperation('direct file locking');
        $file = $this->requireFile('r+');
        $lockType = $exclusive ? LOCK_EX : LOCK_SH;
        $lockType |= LOCK_NB;

        $startTime = time();

        while (!$file->flock($lockType)) {
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                throw new FileAccessException("Timeout reached while trying to acquire lock on file: {$this->filePath}.");
            }
            usleep(100000); // Wait 100 ms before retrying
        }

        return $this;
    }

    /**
     * Get the public URL for this file.
     *
     * @param array<string, mixed> $config Additional configuration for URL generation.
     * @return string The public URL.
     * @throws FileNotFoundException If the file does not exist.
     */
    public function publicUrl(array $config = []): string
    {
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }

        return FlysystemHelper::publicUrl($this->filePath, $config);
    }

    /**
     * Read content from the file.
     *
     * @throws FileNotFoundException
     */
    public function read(): string
    {
        $this->isReadable();

        return FlysystemHelper::read($this->filePath);
    }

    /**
     * Read the file as a stream.
     *
     * @return mixed The file stream resource.
     * @throws FileNotFoundException If the file is not readable.
     */
    public function readStream(): mixed
    {
        $this->isReadable();

        return FlysystemHelper::readStream($this->filePath);
    }

    /**
     * Rename or move the file to a new location.
     */
    public function rename(string $newPath): self
    {
        $this->assertPolicy('rename', $this->filePath, ['destination' => $newPath]);
        $newPath = PathHelper::normalize($newPath);
        $oldPath = $this->filePath;
        $this->recordFileState($oldPath);
        $this->recordFileState($newPath);

        try {
            FlysystemHelper::move($this->filePath, $newPath);
        } catch (\Throwable $e) {
            throw new FileAccessException("Unable to rename or move file to $newPath.", 0, $e);
        }
        $this->filePath = $newPath;
        $this->initFile(); // Reinitialize file object with new path
        $this->audit('rename', ['from' => $oldPath, 'to' => $newPath]);

        return $this;
    }

    /**
     * Rollback the current transaction, reverting all changes.
     *
     * @return self This instance for method chaining.
     */
    public function rollbackTransaction(): self
    {
        $this->assertTransactionActive('rollback');
        $journal = $this->transactionJournal;
        if (!$journal instanceof FileTransactionJournal) {
            throw new TransactionStateException('Transaction journal is unavailable.');
        }
        $journal->rollback();
        $this->filePath = $journal->originalPath;
        $this->file = null;
        $this->transactionActive = false;
        $this->transactionJournal = null;

        return $this;
    }

    /**
     * Search for a term in the file using OS-native commands and return matching lines.
     *
     * @return list<string>
     */
    public function searchContent(string $searchTerm): array
    {
        $this->assertLocalOperation('native content searching');
        $command = escapeshellarg($this->filePath);
        $escapedTerm = escapeshellarg($searchTerm);

        $output = [];
        $returnVar = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            exec("findstr /I $escapedTerm $command", $output, $returnVar);
        } else {
            exec("grep -i $escapedTerm $command", $output, $returnVar);
        }

        if ($returnVar !== 0 && empty($output)) {
            return [];
        }

        return $output;
    }

    /**
     * Set the audit trail for logging operations.
     *
     * @param AuditTrail $auditTrail The audit trail instance.
     * @return self This instance for method chaining.
     */
    public function setAuditTrail(AuditTrail $auditTrail): self
    {
        $this->auditTrail = $auditTrail;

        return $this;
    }

    /**
     * Set the execution strategy for file operations.
     *
     * @param ExecutionStrategy $executionStrategy The execution strategy to use.
     * @return self This instance for method chaining.
     */
    public function setExecutionStrategy(ExecutionStrategy $executionStrategy): self
    {
        $this->executionStrategy = $executionStrategy;

        return $this;
    }

    /**
     * Set file group.
     */
    public function setGroup(int $groupId): self
    {
        return $this->applyOwnershipChange(
            'set-group',
            $groupId,
            static fn(string $path, int $id): bool => chgrp($path, $id),
            'group',
        );
    }

    /**
     * Set file owner.
     */
    public function setOwner(int $ownerId): self
    {
        return $this->applyOwnershipChange(
            'set-owner',
            $ownerId,
            static fn(string $path, int $id): bool => chown($path, $id),
            'owner',
        );
    }

    /**
     * Set file permissions.
     */
    public function setPermissions(int $permissions): self
    {
        $this->assertLocalOperation('POSIX permissions');
        $this->assertPolicy('set-permissions', $this->filePath);
        if (!$this->exists()) {
            throw new FileNotFoundException("File does not exist at $this->filePath.");
        }
        $this->recordFileState($this->filePath);
        if (!chmod($this->filePath, $permissions)) {
            throw new FileAccessException("Unable to set permissions for file: {$this->filePath}.");
        }
        $this->audit('set-permissions', ['path' => $this->filePath, 'permissions' => $permissions]);

        return $this;
    }

    /**
     * Set the policy engine for access control.
     *
     * @param PolicyEngine $policyEngine The policy engine instance.
     * @return self This instance for method chaining.
     */
    public function setPolicyEngine(PolicyEngine $policyEngine): self
    {
        $this->policyEngine = $policyEngine;

        return $this;
    }

    /**
     * Set the visibility of the file.
     *
     * @param string $visibility The visibility to set (e.g., 'public' or 'private').
     * @return self This instance for method chaining.
     */
    public function setVisibility(string $visibility): self
    {
        $this->assertPolicy('set-visibility', $this->filePath, ['visibility' => $visibility]);
        FlysystemHelper::setVisibility($this->filePath, $visibility);
        $this->audit('set-visibility', ['path' => $this->filePath, 'visibility' => $visibility]);

        return $this;
    }

    /**
     * Get a temporary URL for this file.
     *
     * @param DateTimeInterface $expiresAt The expiration time for the URL.
     * @param array<string, mixed> $config Additional configuration for URL generation.
     * @return string The temporary URL.
     * @throws FileNotFoundException If the file does not exist.
     */
    public function temporaryUrl(DateTimeInterface $expiresAt, array $config = []): string
    {
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }

        return FlysystemHelper::temporaryUrl($this->filePath, $expiresAt, $config);
    }

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $callback The callback to execute. Receives this instance as argument.
     * @return mixed The result of the callback.
     * @throws \Throwable Re-throws any exception after rollback.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commitTransaction();

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollbackTransaction();
            } catch (\Throwable $rollbackFailure) {
                throw new FileAccessException(
                    'Transaction failed and rollback was incomplete: ' . $rollbackFailure->getMessage(),
                    0,
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Unlock the file.
     */
    public function unlock(): self
    {
        $this->assertLocalOperation('direct file locking');
        $this->requireFile()->flock(LOCK_UN);

        return $this;
    }

    /**
     * Overwrite the file with new content.
     */
    public function update(string $content): self
    {
        $this->assertPolicy('update', $this->filePath);
        $this->recordFileState($this->filePath);
        FlysystemHelper::write($this->filePath, $content);
        $this->audit('update', ['path' => $this->filePath, 'bytes' => strlen($content)]);

        return $this;
    }

    /**
     * Verify the file's checksum against an expected value.
     *
     * @param string $expectedChecksum The expected checksum.
     * @param string $algorithm The hash algorithm to use. Defaults to 'sha256'.
     * @return bool True if the checksum matches, false otherwise.
     * @throws FileAccessException If the algorithm is not supported.
     * @throws FileNotFoundException If the file does not exist.
     */
    public function verifyChecksum(string $expectedChecksum, string $algorithm = 'sha256'): bool
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new FileAccessException("Unsupported checksum algorithm: {$algorithm}");
        }
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }

        $fileHash = FlysystemHelper::checksum($this->filePath, $algorithm);

        return is_string($fileHash) && hash_equals($expectedChecksum, $fileHash);
    }

    /**
     * Get the visibility of the file.
     *
     * @return string|null The visibility, or null if not available.
     * @throws FileNotFoundException If the file does not exist.
     */
    public function visibility(): ?string
    {
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }

        return FlysystemHelper::visibility($this->filePath);
    }

    /**
     * Overwrite file content and verify checksum.
     */
    public function writeAndVerify(string $content, string $algorithm = 'sha256'): self
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new FileAccessException("Unsupported checksum algorithm: {$algorithm}");
        }

        $this->update($content);
        $expected = hash($algorithm, $content);
        if (!$this->verifyChecksum($expected, $algorithm)) {
            throw new FileAccessException("Checksum verification failed for {$this->filePath}.");
        }

        return $this;
    }

    /**
     * Write to the file from a stream.
     *
     * @param mixed $stream The stream resource to write from.
     * @param array<string, mixed> $config Additional configuration for the write operation.
     * @return self This instance for method chaining.
     */
    public function writeStream(mixed $stream, array $config = []): self
    {
        $this->assertPolicy('write-stream', $this->filePath);
        $this->recordFileState($this->filePath);
        FlysystemHelper::writeStream($this->filePath, $stream, $config);
        $this->audit('write-stream', ['path' => $this->filePath]);

        return $this;
    }

    /**
     * Initialize the SplFileObject.
     */
    protected function initFile(string $mode = 'r'): self
    {
        $this->assertLocalOperation('direct file handles');
        $this->file = new SplFileObject($this->filePath, $mode);

        return $this;
    }

    private function applyOwnershipChange(string $action, int $value, callable $updater, string $label): self
    {
        $this->assertLocalOperation($label . ' changes');
        $this->assertPolicy($action, $this->filePath);
        $this->recordFileState($this->filePath);
        if (!$updater($this->filePath, $value)) {
            throw new FileAccessException("Unable to change {$label} for file: {$this->filePath}.");
        }

        $this->audit($action, ['path' => $this->filePath, $label => $value]);

        return $this;
    }

    private function assertLocalOperation(string $operation): void
    {
        if (!FlysystemHelper::isLocalPath($this->filePath)) {
            throw new UnsupportedStorageOperationException(
                "{$operation} is only supported for local filesystem paths: {$this->filePath}",
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertPolicy(string $operation, string $path, array $context = []): void
    {
        $this->policyEngine?->assertAllowed($operation, PathHelper::normalize($path), $context);
    }

    private function assertTransactionActive(string $operation): void
    {
        if (!$this->transactionActive) {
            throw new TransactionStateException("Cannot {$operation} without an active transaction.");
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $operation, array $context = []): void
    {
        $path = $context['path'] ?? $this->filePath;
        $context['path'] = PathHelper::normalize(is_string($path) ? $path : $this->filePath);
        $this->auditTrail?->log($operation, $context);
    }

    /**
     * Determine MIME type using available extensions with graceful fallback.
     */
    private function determineMimeType(): ?string
    {
        try {
            $mime = FlysystemHelper::mimeType($this->filePath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        } catch (\Throwable) {
            // Fall back to built-in detectors below.
        }

        if (!PathHelper::hasScheme($this->filePath) && is_file($this->filePath) && class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($this->filePath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return null;
    }

    private function emitCopyProgress(?callable $progress, string $destination, int $current): void
    {
        if (!is_callable($progress)) {
            return;
        }

        $progress([
            'operation' => 'copy',
            'path' => $this->filePath,
            'destination' => $destination,
            'current' => $current,
            'total' => 1,
        ]);
    }

    private function performCopy(string $destination): void
    {
        if ($this->executionStrategy === ExecutionStrategy::NATIVE) {
            $this->assertLocalOperation('native copy');
            if (!NativeOperationsAdapter::canUseNativeFileCopy()) {
                throw new NativeExecutionException('Native file copy executable is unavailable.');
            }
            $native = NativeOperationsAdapter::copyFile($this->filePath, $destination);
            if (!$native->success) {
                throw new NativeExecutionException(
                    "Native file copy failed with exit code {$native->exitCode}: {$native->command}",
                );
            }

            return;
        }

        if (
            $this->executionStrategy === ExecutionStrategy::AUTO
            && FlysystemHelper::isLocalPath($this->filePath)
            && FlysystemHelper::isLocalPath($destination)
            && NativeOperationsAdapter::canUseNativeFileCopy()
        ) {
            $native = NativeOperationsAdapter::copyFile($this->filePath, $destination);
            if ($native->success) {
                return;
            }
        }

        try {
            FlysystemHelper::copy($this->filePath, $destination);
        } catch (\Throwable $e) {
            throw new FileAccessException("Unable to copy file to $destination.", 0, $e);
        }
    }

    private function recordFileState(string $path): void
    {
        if (!$this->transactionActive) {
            return;
        }

        if (!FlysystemHelper::isLocalPath($path)) {
            throw new UnsupportedStorageOperationException('Transactions only support local filesystem paths.');
        }

        $this->transactionJournal?->record($path);
    }

    private function requireFile(string $mode = 'r'): SplFileObject
    {
        if (!$this->file instanceof SplFileObject) {
            $this->initFile($mode);
        }

        if (!$this->file instanceof SplFileObject) {
            throw new FileAccessException("Unable to initialize file object for {$this->filePath}.");
        }

        return $this->file;
    }
}
