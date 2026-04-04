<?php

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use Infocyph\Pathwise\Native\NativeOperationsAdapter;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Security\PolicyEngine;
use Infocyph\Pathwise\Utils\PathHelper;
use SplFileInfo;
use SplFileObject;

class FileOperations
{
    protected ?SplFileObject $file = null;
    private ?AuditTrail $auditTrail = null;
    private ExecutionStrategy $executionStrategy = ExecutionStrategy::AUTO;
    private ?PolicyEngine $policyEngine = null;
    /** @var array<int, callable> */
    private array $rollbackActions = [];
    private bool $transactionActive = false;

    /**
     * Constructor to initialize the file path.
     *
     * @param string $filePath
     */
    public function __construct(protected string $filePath)
    {
        $this->filePath = PathHelper::normalize($filePath);
    }

    /**
     * Append content to the file.
     *
     * @param string $content
     * @return self
     */
    public function append(string $content): self
    {
        $this->assertPolicy('append', $this->filePath);
        $previousContent = $this->exists() ? $this->read() : null;
        $this->recordRollback(function () use ($previousContent): void {
            if ($previousContent === null) {
                return;
            }
            file_put_contents($this->filePath, $previousContent);
        });

        $this->initFile('a');
        $this->file->fwrite($content);
        $this->audit('append', ['path' => $this->filePath, 'bytes' => strlen($content)]);
        return $this;
    }

    public function beginTransaction(): self
    {
        $this->transactionActive = true;
        $this->rollbackActions = [];

        return $this;
    }

    public function commitTransaction(): self
    {
        $this->transactionActive = false;
        $this->rollbackActions = [];

        return $this;
    }

    /**
     * Copy the file to a new location.
     *
     * @param string $destination
     * @return self
     */
    public function copy(string $destination, ?callable $progress = null): self
    {
        $this->assertPolicy('copy', $this->filePath, ['destination' => $destination]);

        if (is_callable($progress)) {
            $progress([
                'operation' => 'copy',
                'path' => $this->filePath,
                'destination' => $destination,
                'current' => 0,
                'total' => 1,
            ]);
        }

        $copied = false;
        if ($this->executionStrategy !== ExecutionStrategy::PHP && NativeOperationsAdapter::canUseNativeFileCopy()) {
            $native = NativeOperationsAdapter::copyFile($this->filePath, $destination);
            $copied = $native['success'];
        }

        if (!$copied && !copy($this->filePath, $destination)) {
            throw new FileAccessException("Unable to copy file to $destination.");
        }
        $this->recordRollback(function () use ($destination): void {
            if (is_file($destination)) {
                unlink($destination);
            }
        });

        if (is_callable($progress)) {
            $progress([
                'operation' => 'copy',
                'path' => $this->filePath,
                'destination' => $destination,
                'current' => 1,
                'total' => 1,
            ]);
        }

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

        $sourceHash = hash_file($algorithm, $this->filePath);
        $destinationHash = hash_file($algorithm, $destination);
        if (!is_string($sourceHash) || !is_string($destinationHash) || !hash_equals($sourceHash, $destinationHash)) {
            throw new FileAccessException("Checksum verification failed after copying to {$destination}.");
        }

        return $this;
    }

    /**
     * Create or overwrite the file with optional content.
     *
     * @param string|null $content
     * @return self
     */
    public function create(?string $content = ''): self
    {
        $this->assertPolicy('create', $this->filePath);
        $hadFile = $this->exists();
        $previousContent = $hadFile ? $this->read() : null;
        $this->recordRollback(function () use ($hadFile, $previousContent): void {
            if ($hadFile) {
                file_put_contents($this->filePath, (string) $previousContent);
            } elseif (is_file($this->filePath)) {
                unlink($this->filePath);
            }
        });

        $this->initFile('w');
        if ($content) {
            $this->file->fwrite($content);
        }
        $this->audit('create', ['path' => $this->filePath]);
        return $this;
    }

    /**
     * Delete the file.
     *
     * @return self
     */
    public function delete(): self
    {
        $this->assertPolicy('delete', $this->filePath);
        if (!$this->exists()) {
            throw new FileNotFoundException("File does not exist at $this->filePath.");
        }
        $content = $this->read();
        $this->recordRollback(function () use ($content): void {
            file_put_contents($this->filePath, $content);
        });
        if (!unlink($this->filePath)) {
            throw new FileAccessException("Unable to delete file at $this->filePath.");
        }
        $this->audit('delete', ['path' => $this->filePath]);
        return $this;
    }

    /**
     * Check if a file exists at the given path.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return file_exists($this->filePath);
    }

    /**
     * Get the line count of the file using SplFileObject.
     *
     * @return int
     */
    public function getLineCount(): int
    {
        $this->initFile();
        $this->file->seek(PHP_INT_MAX);
        return $this->file->key() + 1;
    }

    /**
     * Get all metadata for the file.
     *
     * @return array
     */
    public function getMetadata(): array
    {
        $info = new SplFileInfo($this->filePath);

        return [
            'permissions' => substr(sprintf('%o', $info->getPerms()), -4),
            'size' => $info->getSize(),
            'last_modified' => $info->getMTime(),
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
     * @return bool
     * @throws FileNotFoundException
     */
    public function isReadable(): bool
    {
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }
        return is_readable($this->filePath);
    }

    /**
     * Open the file with a lock, optionally with a timeout.
     *
     * @param bool $exclusive
     * @param int $timeout
     * @return self
     * @throws FileAccessException
     */
    public function openWithLock(bool $exclusive = true, int $timeout = 0): self
    {
        $this->initFile('r+');
        $lockType = $exclusive ? LOCK_EX : LOCK_SH;
        $lockType |= LOCK_NB;

        $startTime = time();

        while (!$this->file->flock($lockType)) {
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                throw new FileAccessException("Timeout reached while trying to acquire lock on file: {$this->filePath}.");
            }
            usleep(100000); // Wait 100 ms before retrying
        }

        return $this;
    }

    /**
     * Read content from the file.
     *
     * @return string
     * @throws FileNotFoundException
     */
    public function read(): string
    {
        $this->isReadable();
        $this->initFile();
        $size = $this->file->getSize();
        if ($size === 0) {
            return '';
        }

        return $this->file->fread($size);
    }

    /**
     * Rename or move the file to a new location.
     *
     * @param string $newPath
     * @return self
     */
    public function rename(string $newPath): self
    {
        $this->assertPolicy('rename', $this->filePath, ['destination' => $newPath]);
        if (!rename($this->filePath, $newPath)) {
            throw new FileAccessException("Unable to rename or move file to $newPath.");
        }
        $oldPath = $this->filePath;
        $this->recordRollback(function () use ($oldPath, $newPath): void {
            if (is_file($newPath)) {
                rename($newPath, $oldPath);
            }
        });
        $this->filePath = $newPath;
        $this->initFile(); // Reinitialize file object with new path
        $this->audit('rename', ['from' => $oldPath, 'to' => $newPath]);
        return $this;
    }

    public function rollbackTransaction(): self
    {
        for ($i = count($this->rollbackActions) - 1; $i >= 0; $i--) {
            ($this->rollbackActions[$i])();
        }
        $this->transactionActive = false;
        $this->rollbackActions = [];

        return $this;
    }

    /**
     * Search for a term in the file using OS-native commands and return matching lines.
     *
     * @param string $searchTerm
     * @return array
     */
    public function searchContent(string $searchTerm): array
    {
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

    public function setAuditTrail(AuditTrail $auditTrail): self
    {
        $this->auditTrail = $auditTrail;

        return $this;
    }

    public function setExecutionStrategy(ExecutionStrategy $executionStrategy): self
    {
        $this->executionStrategy = $executionStrategy;

        return $this;
    }

    /**
     * Set file group.
     *
     * @param int $groupId
     * @return self
     */
    public function setGroup(int $groupId): self
    {
        $this->assertPolicy('set-group', $this->filePath);
        if (!chgrp($this->filePath, $groupId)) {
            throw new FileAccessException("Unable to change group for file: {$this->filePath}.");
        }
        $this->audit('set-group', ['path' => $this->filePath, 'group' => $groupId]);
        return $this;
    }

    /**
     * Set file owner.
     *
     * @param int $ownerId
     * @return self
     */
    public function setOwner(int $ownerId): self
    {
        $this->assertPolicy('set-owner', $this->filePath);
        if (!chown($this->filePath, $ownerId)) {
            throw new FileAccessException("Unable to change owner for file: {$this->filePath}.");
        }
        $this->audit('set-owner', ['path' => $this->filePath, 'owner' => $ownerId]);
        return $this;
    }

    /**
     * Set file permissions.
     *
     * @param int $permissions
     * @return self
     */
    public function setPermissions(int $permissions): self
    {
        $this->assertPolicy('set-permissions', $this->filePath);
        if (!$this->exists()) {
            throw new FileNotFoundException("File does not exist at $this->filePath.");
        }
        $previous = fileperms($this->filePath);
        if (is_int($previous)) {
            $this->recordRollback(function () use ($previous): void {
                chmod($this->filePath, $previous & 0777);
            });
        }
        if (!chmod($this->filePath, $permissions)) {
            throw new FileAccessException("Unable to set permissions for file: {$this->filePath}.");
        }
        $this->audit('set-permissions', ['path' => $this->filePath, 'permissions' => $permissions]);
        return $this;
    }

    public function setPolicyEngine(PolicyEngine $policyEngine): self
    {
        $this->policyEngine = $policyEngine;

        return $this;
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commitTransaction();

            return $result;
        } catch (\Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Unlock the file.
     *
     * @return self
     */
    public function unlock(): self
    {
        $this->file->flock(LOCK_UN);
        return $this;
    }

    /**
     * Overwrite the file with new content.
     *
     * @param string $content
     * @return self
     */
    public function update(string $content): self
    {
        $this->assertPolicy('update', $this->filePath);
        $previous = $this->exists() ? $this->read() : null;
        $this->recordRollback(function () use ($previous): void {
            if ($previous === null) {
                return;
            }
            file_put_contents($this->filePath, $previous);
        });
        $this->initFile('w');
        $this->file->fwrite($content);
        $this->audit('update', ['path' => $this->filePath, 'bytes' => strlen($content)]);
        return $this;
    }

    public function verifyChecksum(string $expectedChecksum, string $algorithm = 'sha256'): bool
    {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new FileAccessException("Unsupported checksum algorithm: {$algorithm}");
        }
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }

        $fileHash = hash_file($algorithm, $this->filePath);

        return is_string($fileHash) && hash_equals($expectedChecksum, $fileHash);
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
     * Initialize the SplFileObject.
     *
     * @param string $mode
     * @return self
     */
    protected function initFile(string $mode = 'r'): self
    {
        $this->file = new SplFileObject($this->filePath, $mode);
        return $this;
    }

    private function assertPolicy(string $operation, string $path, array $context = []): void
    {
        $this->policyEngine?->assertAllowed($operation, PathHelper::normalize($path), $context);
    }

    private function audit(string $operation, array $context = []): void
    {
        $context['path'] = PathHelper::normalize((string) ($context['path'] ?? $this->filePath));
        $this->auditTrail?->log($operation, $context);
    }

    /**
     * Determine MIME type using available extensions with graceful fallback.
     */
    private function determineMimeType(): ?string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($this->filePath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($this->filePath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        $extension = strtolower((string) pathinfo($this->filePath, PATHINFO_EXTENSION));
        return match ($extension) {
            'txt', 'log' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'html', 'htm' => 'text/html',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            default => null,
        };
    }

    private function recordRollback(callable $rollbackAction): void
    {
        if (!$this->transactionActive) {
            return;
        }

        $this->rollbackActions[] = $rollbackAction;
    }
}
