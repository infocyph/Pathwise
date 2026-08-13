<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Concerns;

use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

/**
 * @phpstan-type UploadInput array{
 *     error: int,
 *     size: int|numeric-string,
 *     tmp_name: string,
 *     name: string
 * }
 * @phpstan-type ChunkManifest array{
 *     uploadId: string,
 *     originalFilename: string,
 *     totalChunks: int,
 *     createdAt: int
 * }
 */
trait UploadProcessorChunkConcern
{
    private function appendChunkToStream(string $chunkPath, mixed $output, int $index): void
    {
        if (!is_resource($output)) {
            throw new UploadException("Invalid merge stream for chunk index {$index}.");
        }

        $input = FlysystemHelper::readStream($chunkPath);
        if (!is_resource($input)) {
            throw new UploadException("Failed to read chunk index {$index}.");
        }

        try {
            stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
        }
    }

    /** @param ChunkManifest $manifest */
    private function assertChunkManifestIdentity(
        array $manifest,
        string $uploadId,
        string $originalFilename,
        int $totalChunks,
    ): void {
        if (
            $manifest['uploadId'] !== $uploadId
            || $manifest['originalFilename'] !== $originalFilename
            || $manifest['totalChunks'] !== $totalChunks
        ) {
            throw new UploadException('Chunk upload metadata does not match the existing session.');
        }
    }

    /**
     */
    private function cleanupChunkUploadArtifacts(string $uploadId, string $chunkDirectory): void
    {
        $manifestPath = $this->getChunkManifestPath($uploadId);
        if (FlysystemHelper::fileExists($manifestPath)) {
            FlysystemHelper::delete($manifestPath);
        }
        if (FlysystemHelper::directoryExists($chunkDirectory)) {
            FlysystemHelper::deleteDirectory($chunkDirectory);
        }
    }

    private function getChunkDirectory(string $uploadId): string
    {
        $safeUploadId = preg_replace('/[^A-Za-z0-9_\-]/', '', $uploadId) ?: 'upload';
        $baseTemp = $this->tempDir ? rtrim($this->tempDir, '/\\') : sys_get_temp_dir();

        return PathHelper::join($baseTemp, 'pathwise_chunks', $safeUploadId);
    }

    private function getChunkManifestPath(string $uploadId): string
    {
        return PathHelper::join($this->getChunkDirectory($uploadId), 'manifest.json');
    }

    /**
     * @return ChunkManifest|null
     */
    private function loadChunkManifest(string $uploadId): ?array
    {
        $path = $this->getChunkManifestPath($uploadId);
        if (!FlysystemHelper::fileExists($path)) {
            return null;
        }

        $content = FlysystemHelper::read($path);

        $manifest = json_decode($content, true);
        if (!is_array($manifest)) {
            throw new UploadException('Invalid chunk manifest.');
        }

        $originalFilename = $manifest['originalFilename'] ?? null;
        $storedUploadId = $manifest['uploadId'] ?? null;
        $createdAt = $manifest['createdAt'] ?? null;
        $totalChunks = $manifest['totalChunks'] ?? null;

        if (!is_string($originalFilename) || !is_string($storedUploadId) || $storedUploadId !== $uploadId) {
            throw new UploadException('Invalid chunk manifest.');
        }

        if (!is_int($createdAt) || $createdAt < 0) {
            throw new UploadException('Invalid chunk manifest.');
        }

        if (!is_int($totalChunks) || $totalChunks < 1) {
            throw new UploadException('Invalid chunk manifest.');
        }

        return [
            'uploadId' => $storedUploadId,
            'originalFilename' => $originalFilename,
            'totalChunks' => $totalChunks,
            'createdAt' => $createdAt,
        ];
    }

    /**
     */
    private function mergeChunksToDestination(string $chunkDirectory, int $totalChunks, string $destination): void
    {
        $output = fopen('php://temp', 'rb+');
        if ($output === false) {
            throw new UploadException('Failed to create destination file for chunk merge.');
        }

        /** @var resource $output */

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $this->resolveChunkPath($chunkDirectory, $i);
                $this->appendChunkToStream($chunkPath, $output, $i);
            }

            rewind($output);
            FlysystemHelper::writeStream($destination, $output);
        } finally {
            fclose($output);
        }
    }

    /** @return array<int|string, string> */
    private function receivedChunkMap(string $chunkDirectory, int $totalChunks): array
    {
        $received = [];
        for ($index = 0; $index < $totalChunks; $index++) {
            $name = sprintf('chunk_%06d.part', $index);
            if (FlysystemHelper::fileExists(PathHelper::join($chunkDirectory, $name))) {
                $received[(string) $index] = $name;
            }
        }

        return $received;
    }

    /**
     */
    private function resolveChunkPath(string $chunkDirectory, int $index): string
    {
        $chunkPath = PathHelper::join($chunkDirectory, sprintf('chunk_%06d.part', $index));
        if (!FlysystemHelper::fileExists($chunkPath)) {
            throw new UploadException("Missing chunk file for index {$index}.");
        }

        return $chunkPath;
    }

    /**
     * @return array{0: ChunkManifest, 1: int}
     */
    private function resolveCompleteChunkState(string $uploadId): array
    {
        $manifest = $this->loadChunkManifest($uploadId);
        if ($manifest === null) {
            throw new UploadException("Upload session not found: {$uploadId}");
        }

        $totalChunks = $manifest['totalChunks'];
        $chunkDirectory = $this->getChunkDirectory($uploadId);
        $received = $this->receivedChunkMap($chunkDirectory, $totalChunks);
        if ($totalChunks < 1 || count($received) !== $totalChunks) {
            throw new UploadException('Upload is not complete.');
        }
        if ($this->maxChunkCount > 0 && $totalChunks > $this->maxChunkCount) {
            throw new UploadException('Total chunks exceed configured limit.');
        }

        return [$manifest, $totalChunks];
    }

    /**
     * @param ChunkManifest $manifest
     */
    private function saveChunkManifest(string $uploadId, array $manifest): void
    {
        $path = $this->getChunkManifestPath($uploadId);

        try {
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UploadException('Failed to persist chunk manifest.');
        }

        FlysystemHelper::write($path, $json);
    }

    /**
     * @param UploadInput $chunkFile
     */
    private function validateChunkLimits(array $chunkFile, int $totalChunks): void
    {
        if ($this->maxChunkCount > 0 && $totalChunks > $this->maxChunkCount) {
            throw new UploadException('Total chunks exceed configured limit.');
        }

        $chunkSize = $this->normalizeUploadSize($chunkFile['size']);
        if ($this->maxChunkSize > 0 && $chunkSize > $this->maxChunkSize) {
            throw new FileSizeExceededException('Chunk exceeds configured size limit.');
        }
    }

    /**
     * @param UploadInput $chunkFile
     */
    private function validateChunkUploadRequest(array $chunkFile, string $uploadId, int $chunkIndex, int $totalChunks, string $originalFilename): void
    {
        $this->validateUploadId($uploadId);

        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            throw new UploadException('Invalid chunk metadata.');
        }

        $this->validateChunkLimits($chunkFile, $totalChunks);
        $this->validateFileExtension(pathinfo($originalFilename, PATHINFO_EXTENSION));
    }

    private function validateUploadId(string $uploadId): void
    {
        if ($uploadId === '' || strlen($uploadId) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $uploadId) !== 1) {
            throw new UploadException('Invalid upload session id.');
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withChunkSessionLock(string $uploadId, callable $operation): mixed
    {
        $chunkDirectory = $this->getChunkDirectory($uploadId);
        if (!FlysystemHelper::isLocalPath($chunkDirectory)) {
            return $operation();
        }

        $lockDirectory = dirname($chunkDirectory);
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0700, true) && !is_dir($lockDirectory)) {
            throw new UploadException('Unable to create chunk lock directory.');
        }
        $lock = fopen(PathHelper::join($lockDirectory, ".{$uploadId}.lock"), 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new UploadException('Unable to lock chunk upload session.');
        }

        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
