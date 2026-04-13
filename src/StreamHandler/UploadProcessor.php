<?php

namespace Infocyph\Pathwise\StreamHandler;

use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\MetadataHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use Psr\Log\LoggerInterface;

class UploadProcessor
{
    private const array VALIDATION_PROFILES = [
        'image' => [
            'allowedFileTypes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'maxFileSize' => 10 * 1024 * 1024,
            'maxImageWidth' => 4096,
            'maxImageHeight' => 4096,
        ],
        'video' => [
            'allowedFileTypes' => ['video/mp4', 'video/webm', 'video/quicktime'],
            'maxFileSize' => 200 * 1024 * 1024,
            'maxImageWidth' => 0,
            'maxImageHeight' => 0,
        ],
        'document' => [
            'allowedFileTypes' => [
                'text/plain',
                'text/csv',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'maxFileSize' => 25 * 1024 * 1024,
            'maxImageWidth' => 0,
            'maxImageHeight' => 0,
        ],
    ];
    private array $allowedFileTypes = [];
    private LoggerInterface $logger;
    private mixed $malwareScanner = null;
    private int $maxFileSize = 30720;
    private int $maxImageHeight = 0;
    private int $maxImageWidth = 0;
    private string $namingStrategy = 'hash';
    private ?string $tempDir = null;

    private string $uploadDir;
    private bool $useDateDirectories = false;
    private ?string $validationProfile = null;

    /**
     * Finalize a resumable upload by assembling all uploaded chunks.
     */
    public function finalizeChunkUpload(string $uploadId): string
    {
        if (empty($this->uploadDir)) {
            throw new UploadException('Upload directory is not set.');
        }

        [$manifest, $totalChunks, $received] = $this->resolveCompleteChunkState($uploadId);
        $originalFilename = (string) ($manifest['originalFilename'] ?? 'upload.bin');
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $fileName = $this->generateFileName(null, $extension);
        $destination = $this->getUniqueDestination($fileName);
        $chunkDirectory = $this->getChunkDirectory($uploadId);

        $this->mergeChunksToDestination($chunkDirectory, $received, $totalChunks, $destination);
        $this->validateFinalizedUpload($destination);
        $this->cleanupChunkUploadArtifacts($uploadId, $chunkDirectory, $received);

        return $destination;
    }

    /**
     * Get detailed info about the current configuration and settings.
     */
    public function getInfo(): array
    {
        return [
            'uploadDir' => $this->uploadDir ?? 'Not Set',
            'useDateDirectories' => $this->useDateDirectories,
            'tempDir' => $this->tempDir ?? sys_get_temp_dir(),
            'allowedFileTypes' => $this->allowedFileTypes,
            'maxFileSize' => $this->maxFileSize,
            'namingStrategy' => $this->namingStrategy,
            'validationProfile' => $this->validationProfile,
            'hasMalwareScanner' => is_callable($this->malwareScanner),
        ];
    }

    /**
     * Retrieve available validation profiles.
     */
    public function getValidationProfiles(): array
    {
        return array_keys(self::VALIDATION_PROFILES);
    }

    /**
     * Process an upload chunk and persist resumable state.
     *
     * @return array{uploadId: string, receivedChunks: int, totalChunks: int, isComplete: bool}
     */
    public function processChunkUpload(array $chunkFile, string $uploadId, int $chunkIndex, int $totalChunks, string $originalFilename): array
    {
        if (empty($this->uploadDir)) {
            throw new UploadException('Upload directory is not set.');
        }
        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            throw new UploadException('Invalid chunk metadata.');
        }

        $this->validateFile($chunkFile);

        $chunkDirectory = $this->getChunkDirectory($uploadId);
        if (!FlysystemHelper::directoryExists($chunkDirectory)) {
            FlysystemHelper::createDirectory($chunkDirectory);
        }

        $chunkPath = PathHelper::join($chunkDirectory, sprintf('chunk_%06d.part', $chunkIndex));
        $this->moveIncomingFile($chunkFile['tmp_name'], $chunkPath);

        $manifest = $this->loadChunkManifest($uploadId) ?? [
            'uploadId' => $uploadId,
            'originalFilename' => $originalFilename,
            'totalChunks' => $totalChunks,
            'received' => [],
            'createdAt' => time(),
        ];

        $manifest['originalFilename'] = $originalFilename;
        $manifest['totalChunks'] = $totalChunks;
        $manifest['received'][(string) $chunkIndex] = basename($chunkPath);
        ksort($manifest['received']);
        $this->saveChunkManifest($uploadId, $manifest);

        return [
            'uploadId' => $uploadId,
            'receivedChunks' => count($manifest['received']),
            'totalChunks' => $totalChunks,
            'isComplete' => count($manifest['received']) === $totalChunks,
        ];
    }

    /**
     * Process the upload and save the file.
     */
    public function processUpload(array $file): string
    {
        try {
            if (empty($this->uploadDir)) {
                throw new UploadException('Upload directory is not set.');
            }

            $this->validateFile($file);
            $fileType = $this->getFileMimeType($file['tmp_name']);
            $this->validateFileType($fileType);

            if ($this->isImage($fileType)) {
                $this->validateImageDimensions($file['tmp_name']);
            }
            $this->scanForMalware($file['tmp_name'], $fileType);

            $fileName = $this->generateFileName($file['tmp_name'], pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            $destination = $this->getUniqueDestination($fileName);

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                $stream = fopen($file['tmp_name'], 'rb');
                if (!is_resource($stream)) {
                    throw new UploadException('Failed to move uploaded file.');
                }

                try {
                    FlysystemHelper::writeStream($destination, $stream);
                } finally {
                    fclose($stream);
                }

                @unlink($file['tmp_name']);
            }

            // Log upload metadata
            if (isset($this->logger)) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                $callingClass = $backtrace[1]['class'] ?? 'Unknown Class';
                $callingMethod = $backtrace[1]['function'] ?? 'Unknown Method';

                $this->logger->info('File uploaded successfully.', [
                    'fileName' => $fileName,
                    'destination' => $destination,
                    'fileType' => $fileType,
                    'uploader' => [
                        'class' => $callingClass,
                        'method' => $callingMethod,
                    ],
                ]);
            }

            return $destination;
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->error('File upload failed.', [
                    'error' => $e->getMessage(),
                    'file' => $file['name'] ?? 'Unknown',
                ]);
            }

            throw $e;
        }
    }

    /**
     * Configure directory and path settings.
     */
    public function setDirectorySettings(string $uploadDir, bool $useDateDirectories = false, ?string $tempDir = null): void
    {
        $this->uploadDir = PathHelper::normalize($this->sanitizePath($uploadDir));
        $this->useDateDirectories = $useDateDirectories;
        $this->tempDir = $tempDir
            ? PathHelper::normalize($this->sanitizePath($tempDir))
            : sys_get_temp_dir();
        $this->ensureUploadDirectoryExists();
    }

    /**
     * Configure optional image dimension validation.
     */
    public function setImageValidationSettings(int $maxImageWidth = 0, int $maxImageHeight = 0): void
    {
        $this->maxImageWidth = max(0, $maxImageWidth);
        $this->maxImageHeight = max(0, $maxImageHeight);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Configure an optional malware scanner callback.
     *
     * Signature: fn(string $filePath, string $mimeType): bool
     */
    public function setMalwareScanner(callable $scanner): void
    {
        $this->malwareScanner = $scanner;
    }

    /**
     * Configure naming strategy.
     */
    public function setNamingStrategy(string $namingStrategy): void
    {
        if (!in_array($namingStrategy, ['hash', 'timestamp'], true)) {
            throw new UploadException("Invalid naming strategy: $namingStrategy.");
        }
        $this->namingStrategy = $namingStrategy;
    }

    /**
     * Configure validation using a predefined profile.
     */
    public function setValidationProfile(string $profile): void
    {
        if (!isset(self::VALIDATION_PROFILES[$profile])) {
            throw new UploadException("Invalid validation profile: $profile.");
        }

        $config = self::VALIDATION_PROFILES[$profile];
        $this->allowedFileTypes = $config['allowedFileTypes'];
        $this->maxFileSize = $config['maxFileSize'];
        $this->maxImageWidth = $config['maxImageWidth'];
        $this->maxImageHeight = $config['maxImageHeight'];
        $this->validationProfile = $profile;
    }

    /**
     * Configure validation settings.
     */
    public function setValidationSettings(array $allowedFileTypes, int $maxFileSize): void
    {
        $this->allowedFileTypes = $allowedFileTypes;
        $this->maxFileSize = $maxFileSize;
        $this->validationProfile = null;
    }

    private function appendChunkToStream(string $chunkPath, mixed $output, int $index): void
    {
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

    private function cleanupChunkUploadArtifacts(string $uploadId, string $chunkDirectory, array $received): void
    {
        foreach ($received as $chunkName) {
            $chunkPath = PathHelper::join($chunkDirectory, (string) $chunkName);
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

    private function copyImageToInspectionFile(string $filePath, string $tempFile): void
    {
        $stream = FlysystemHelper::readStream($filePath);
        $target = fopen($tempFile, 'wb');
        if (!is_resource($stream) || !is_resource($target)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($tempFile);
            throw new UploadException('Unable to inspect image dimensions.');
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);
    }

    /**
     * Ensure the upload directory exists.
     */
    private function ensureUploadDirectoryExists(): void
    {
        if (!FlysystemHelper::directoryExists($this->uploadDir)) {
            FlysystemHelper::createDirectory($this->uploadDir);
        }
    }

    /**
     * Generate a unique file name based on the strategy and caller info.
     */
    private function generateFileName(?string $dataSource, string $extension): string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callingClass = $backtrace[1]['class'] ?? null;
        $callingMethod = $backtrace[1]['function'] ?? null;

        $shortClass = is_string($callingClass)
            ? (strrchr($callingClass, '\\') !== false ? substr(strrchr($callingClass, '\\'), 1) : $callingClass)
            : 'Upload';
        $classPrefix = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $shortClass) ?: 'upload');

        $methodName = is_string($callingMethod) ? $callingMethod : 'process';
        $methodPrefix = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $methodName) ?: 'process');

        $prefix = "{$classPrefix}_$methodPrefix";

        $hash = match ($this->namingStrategy) {
            'timestamp' => time(),
            default => $dataSource ? sha1_file($dataSource) : sha1(uniqid('', true)),
        };
        $extension = ltrim($extension, '.');

        return $extension !== ''
            ? sprintf('%s_%s.%s', $prefix, $hash, $extension)
            : sprintf('%s_%s', $prefix, $hash);
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
     * Get the MIME type of a file.
     */
    private function getFileMimeType(string $filePath): string
    {
        $mimeType = MetadataHelper::getMimeType($filePath);
        if ($mimeType === null) {
            throw new UploadException('Unable to determine file MIME type.');
        }

        return $mimeType;
    }

    /**
     * Get a unique destination for the uploaded file.
     */
    private function getUniqueDestination(string $fileName): string
    {
        $subDir = $this->useDateDirectories ? date('Y/m/d') : '';
        $destinationDir = $subDir !== ''
            ? PathHelper::join($this->uploadDir, $subDir)
            : $this->uploadDir;

        if (!FlysystemHelper::directoryExists($destinationDir)) {
            FlysystemHelper::createDirectory($destinationDir);
        }

        $destination = PathHelper::join($destinationDir, $fileName);

        if (FlysystemHelper::fileExists($destination)) {
            throw new UploadException('File with the same name already exists.');
        }

        return $destination;
    }

    /**
     * Check if a file is an image.
     */
    private function isImage(string $fileType): bool
    {
        return str_starts_with($fileType, 'image/');
    }

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

        return $manifest;
    }

    private function mergeChunksToDestination(string $chunkDirectory, array $received, int $totalChunks, string $destination): void
    {
        $output = fopen('php://temp', 'rb+');
        if ($output === false) {
            throw new UploadException('Failed to create destination file for chunk merge.');
        }

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

    private function moveIncomingFile(string $source, string $destination): void
    {
        if (is_uploaded_file($source)) {
            if (move_uploaded_file($source, $destination)) {
                return;
            }

            $stream = fopen($source, 'rb');
            if (is_resource($stream)) {
                try {
                    FlysystemHelper::writeStream($destination, $stream);
                } finally {
                    fclose($stream);
                }

                @unlink($source);

                return;
            }

            throw new UploadException('Failed to move uploaded file.');
        }

        if (!@rename($source, $destination)) {
            try {
                FlysystemHelper::copy($source, $destination);
            } catch (\Throwable) {
                throw new UploadException('Failed to move incoming file.');
            }
            FlysystemHelper::delete($source);
        }
    }

    /**
     * @return array{string, bool}
     */
    private function prepareImagePathForInspection(string $filePath): array
    {
        if (!PathHelper::hasScheme($filePath) && is_file($filePath)) {
            return [$filePath, false];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pathwise_img_');
        if ($tempFile === false) {
            throw new UploadException('Unable to create temporary file for image validation.');
        }

        $this->copyImageToInspectionFile($filePath, $tempFile);

        return [$tempFile, true];
    }

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
     * @return array{array, int, array}
     */
    private function resolveCompleteChunkState(string $uploadId): array
    {
        $manifest = $this->loadChunkManifest($uploadId);
        if ($manifest === null) {
            throw new UploadException("Upload session not found: {$uploadId}");
        }

        $totalChunks = (int) ($manifest['totalChunks'] ?? 0);
        $received = (array) ($manifest['received'] ?? []);
        if ($totalChunks < 1 || count($received) !== $totalChunks) {
            throw new UploadException('Upload is not complete.');
        }

        ksort($received);

        return [$manifest, $totalChunks, $received];
    }

    /**
     * Sanitize a path to remove invalid characters.
     */
    private function sanitizePath(string $path): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9\/\\\\:_.-]/', '', $path) ?? '';
        return rtrim($sanitized, '/\\');
    }

    private function saveChunkManifest(string $uploadId, array $manifest): void
    {
        $path = $this->getChunkManifestPath($uploadId);
        $json = json_encode($manifest, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new UploadException('Failed to persist chunk manifest.');
        }

        FlysystemHelper::write($path, $json);
    }

    private function scanForMalware(string $filePath, string $fileType): void
    {
        if (!is_callable($this->malwareScanner)) {
            return;
        }

        try {
            $result = ($this->malwareScanner)($filePath, $fileType);
        } catch (\Throwable $e) {
            throw new UploadException('Malware scanner failed: ' . $e->getMessage(), 0, $e);
        }

        if ($result === false) {
            throw new UploadException('Malware scan failed.');
        }
    }

    /**
     * Validate the uploaded file.
     */
    private function validateFile(array $file): void
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new UploadException('Invalid file upload parameters.');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new UploadException('No file sent.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new FileSizeExceededException('Exceeded file size limit.');
            default:
                throw new UploadException('Unknown errors.');
        }

        $this->validateFileSize($file['size']);
    }

    /**
     * Validate file size.
     */
    private function validateFileSize(int $size): void
    {
        if ($size > $this->maxFileSize) {
            throw new FileSizeExceededException('Exceeded file size limit.');
        }
    }

    /**
     * Validate the file type.
     */
    private function validateFileType(string $fileType): void
    {
        if ($this->allowedFileTypes === []) {
            return;
        }
        if (!in_array($fileType, $this->allowedFileTypes, true)) {
            throw new UploadException('Invalid file format.');
        }
    }

    private function validateFinalizedUpload(string $destination): void
    {
        $finalSize = FlysystemHelper::size($destination);
        $this->validateFileSize($finalSize);

        $fileType = $this->getFileMimeType($destination);
        $this->validateFileType($fileType);
        if ($this->isImage($fileType)) {
            $this->validateImageDimensions($destination);
        }

        $this->scanForMalware($destination, $fileType);
    }

    /**
     * Validate image dimensions.
     */
    private function validateImageDimensions(string $filePath): void
    {
        [$pathForInspection, $cleanup] = $this->prepareImagePathForInspection($filePath);

        try {
            $dimensions = getimagesize($pathForInspection);
        } finally {
            if ($cleanup && is_file($pathForInspection)) {
                @unlink($pathForInspection);
            }
        }

        if (!is_array($dimensions) || count($dimensions) < 2) {
            throw new UploadException('Unable to inspect image dimensions.');
        }

        [$width, $height] = $dimensions;
        if ($this->maxImageWidth > 0 && $width > $this->maxImageWidth) {
            throw new UploadException('Image width exceeds the maximum allowed.');
        }
        if ($this->maxImageHeight > 0 && $height > $this->maxImageHeight) {
            throw new UploadException('Image height exceeds the maximum allowed.');
        }
    }
}
