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
 *     received: array<int|string, string>,
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

    /**
     * @param array<int|string, string> $received
     */
    private function cleanupChunkUploadArtifacts(string $uploadId, string $chunkDirectory, array $received): void
    {
        foreach ($received as $chunkName) {
            $chunkPath = PathHelper::join($chunkDirectory, $chunkName);
            if (FlysystemHelper::fileExists($chunkPath)) {
                FlysystemHelper::delete($chunkPath);
            }
        }

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

        $receivedRaw = $manifest['received'] ?? null;
        if (!is_array($receivedRaw)) {
            throw new UploadException('Invalid chunk manifest.');
        }

        $received = [];
        foreach ($receivedRaw as $chunkIndex => $chunkName) {
            if (!is_string($chunkName)) {
                throw new UploadException('Invalid chunk manifest.');
            }

            $received[$chunkIndex] = $chunkName;
        }

        $originalFilename = $manifest['originalFilename'] ?? null;
        $storedUploadId = $manifest['uploadId'] ?? $uploadId;
        $createdAt = $manifest['createdAt'] ?? time();
        $totalChunks = $manifest['totalChunks'] ?? null;

        if (!is_string($originalFilename) || !is_string($storedUploadId)) {
            throw new UploadException('Invalid chunk manifest.');
        }

        if (!is_int($createdAt) && !is_numeric($createdAt)) {
            throw new UploadException('Invalid chunk manifest.');
        }

        if (!is_int($totalChunks) && !is_numeric($totalChunks)) {
            throw new UploadException('Invalid chunk manifest.');
        }

        return [
            'uploadId' => $storedUploadId,
            'originalFilename' => $originalFilename,
            'totalChunks' => (int) $totalChunks,
            'received' => $received,
            'createdAt' => (int) $createdAt,
        ];
    }

    /**
     * @param array<int|string, string> $received
     */
    private function mergeChunksToDestination(string $chunkDirectory, array $received, int $totalChunks, string $destination): void
    {
        $output = fopen('php://temp', 'rb+');
        if ($output === false) {
            throw new UploadException('Failed to create destination file for chunk merge.');
        }

        /** @var resource $output */

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $this->resolveChunkPath($chunkDirectory, $received, $i);
                $this->appendChunkToStream($chunkPath, $output, $i);
            }

            rewind($output);
            FlysystemHelper::writeStream($destination, $output);
        } finally {
            fclose($output);
        }
    }

    /**
     * @param array<int|string, string> $received
     */
    private function resolveChunkPath(string $chunkDirectory, array $received, int $index): string
    {
        $chunkName = $received[(string) $index] ?? null;
        if (!is_string($chunkName)) {
            throw new UploadException("Missing chunk index {$index}.");
        }

        $chunkPath = PathHelper::join($chunkDirectory, $chunkName);
        if (!FlysystemHelper::fileExists($chunkPath)) {
            throw new UploadException("Missing chunk file for index {$index}.");
        }

        return $chunkPath;
    }

    /**
     * @return array{0: ChunkManifest, 1: int, 2: array<int|string, string>}
     */
    private function resolveCompleteChunkState(string $uploadId): array
    {
        $manifest = $this->loadChunkManifest($uploadId);
        if ($manifest === null) {
            throw new UploadException("Upload session not found: {$uploadId}");
        }

        $totalChunks = $manifest['totalChunks'];
        $received = $manifest['received'];
        if ($totalChunks < 1 || count($received) !== $totalChunks) {
            throw new UploadException('Upload is not complete.');
        }
        if ($this->maxChunkCount > 0 && $totalChunks > $this->maxChunkCount) {
            throw new UploadException('Total chunks exceed configured limit.');
        }

        ksort($received);

        return [$manifest, $totalChunks, $received];
    }

    /**
     * @param ChunkManifest $manifest
     */
    private function saveChunkManifest(string $uploadId, array $manifest): void
    {
        $path = $this->getChunkManifestPath($uploadId);
        $json = json_encode($manifest, JSON_PRETTY_PRINT);
        if ($json === false) {
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
}
