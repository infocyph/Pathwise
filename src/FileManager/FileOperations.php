<?php

namespace Infocyph\Pathwise\FileManager;

use DateTimeInterface;
use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
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
    /** @var array<int, callable> */
    private array $rollbackActions = [];
    private bool $transactionActive = false;

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
    public function append(string $content): self
    {
        $this->assertPolicy('append', $this->filePath);
        $previousContent = $this->exists() ? $this->read() : null;
        $this->recordRollback(function () use ($previousContent): void {
            if ($previousContent === null) {
                return;
            }
            FlysystemHelper::write($this->filePath, $previousContent);
        });
        $newContent = ($previousContent ?? '') . $content;
        FlysystemHelper::write($this->filePath, $newContent);
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

        if (!$copied) {
            try {
                FlysystemHelper::copy($this->filePath, $destination);
            } catch (\Throwable $e) {
                throw new FileAccessException("Unable to copy file to $destination.", 0, $e);
            }
        }
        $this->recordRollback(function () use ($destination): void {
            if (FlysystemHelper::fileExists($destination)) {
                FlysystemHelper::delete($destination);
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
        $hadFile = $this->exists();
        $previousContent = $hadFile ? $this->read() : null;
        $this->recordRollback(function () use ($hadFile, $previousContent): void {
            if ($hadFile) {
                FlysystemHelper::write($this->filePath, (string) $previousContent);
            } elseif (FlysystemHelper::fileExists($this->filePath)) {
                FlysystemHelper::delete($this->filePath);
            }
        });
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
        $content = $this->read();
        $this->recordRollback(function () use ($content): void {
            FlysystemHelper::write($this->filePath, $content);
        });
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
        $this->initFile();
        $this->file->seek(PHP_INT_MAX);
        return $this->file->key() + 1;
    }

    /**
     * Get all metadata for the file.
     */
    public function getMetadata(): array
    {
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
        return is_readable($this->filePath);
    }

    /**
     * Open the file with a lock, optionally with a timeout.
     *
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
        try {
            FlysystemHelper::move($this->filePath, $newPath);
        } catch (\Throwable $e) {
            throw new FileAccessException("Unable to rename or move file to $newPath.", 0, $e);
        }
        $oldPath = $this->filePath;
        $this->recordRollback(function () use ($oldPath, $newPath): void {
            if (FlysystemHelper::fileExists($newPath)) {
                FlysystemHelper::move($newPath, $oldPath);
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

    public function setVisibility(string $visibility): self
    {
        $this->assertPolicy('set-visibility', $this->filePath, ['visibility' => $visibility]);
        FlysystemHelper::setVisibility($this->filePath, $visibility);
        $this->audit('set-visibility', ['path' => $this->filePath, 'visibility' => $visibility]);

        return $this;
    }

    public function temporaryUrl(DateTimeInterface $expiresAt, array $config = []): string
    {
        if (!$this->exists()) {
            throw new FileNotFoundException("File not found at $this->filePath.");
        }

        return FlysystemHelper::temporaryUrl($this->filePath, $expiresAt, $config);
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
     */
    public function unlock(): self
    {
        $this->file->flock(LOCK_UN);
        return $this;
    }

    /**
     * Overwrite the file with new content.
     */
    public function update(string $content): self
    {
        $this->assertPolicy('update', $this->filePath);
        $previous = $this->exists() ? $this->read() : null;
        $this->recordRollback(function () use ($previous): void {
            if ($previous === null) {
                return;
            }
            FlysystemHelper::write($this->filePath, $previous);
        });
        FlysystemHelper::write($this->filePath, $content);
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

        $fileHash = FlysystemHelper::checksum($this->filePath, $algorithm);

        return is_string($fileHash) && hash_equals($expectedChecksum, $fileHash);
    }

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

    public function writeStream(mixed $stream, array $config = []): self
    {
        $this->assertPolicy('write-stream', $this->filePath);
        FlysystemHelper::writeStream($this->filePath, $stream, $config);
        $this->audit('write-stream', ['path' => $this->filePath]);

        return $this;
    }

    /**
     * Initialize the SplFileObject.
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

    private function recordRollback(callable $rollbackAction): void
    {
        if (!$this->transactionActive) {
            return;
        }

        $this->rollbackActions[] = $rollbackAction;
    }
}
