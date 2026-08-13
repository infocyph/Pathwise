<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

use Infocyph\Pathwise\Exceptions\UploadException;

use Infocyph\Pathwise\Results\ChunkUploadState;
use Infocyph\Pathwise\StreamHandler\Concerns\UploadProcessorChunkConcern;
use Infocyph\Pathwise\StreamHandler\Concerns\UploadProcessorValidationConcern;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use Psr\Log\LoggerInterface;

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
 * @phpstan-type UploadInfo array{
 *     uploadDir: string,
 *     useDateDirectories: bool,
 *     tempDir: string,
 *     allowedFileTypes: list<string>,
 *     allowedExtensions: list<string>,
 *     blockedExtensions: list<string>,
 *     maxFileSize: int,
 *     maxChunkCount: int,
 *     maxChunkSize: int,
 *     namingStrategy: string,
 *     validationProfile: string|null,
 *     hasMalwareScanner: bool,
 *     requireMalwareScan: bool,
 *     strictContentTypeValidation: bool
 * }
 */
class UploadProcessor
{
    use UploadProcessorChunkConcern;
    use UploadProcessorValidationConcern;

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

    /** @var list<string> */
    private array $allowedExtensions = [];

    /** @var list<string> */
    private array $allowedFileTypes = [];

    /** @var list<string> */
    private array $blockedExtensions = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com'];

    private LoggerInterface $logger;

    private mixed $malwareScanner = null;

    private int $maxChunkCount = 0;

    private int $maxChunkSize = 0;

    private int $maxFileSize = 25 * 1024 * 1024;

    private int $maxImageHeight = 0;

    private int $maxImageWidth = 0;

    private string $namingStrategy = 'hash';

    private bool $requireMalwareScan = false;

    private bool $strictContentTypeValidation = true;

    private ?string $tempDir = null;

    private string $uploadDir;

    private bool $useDateDirectories = false;

    private ?string $validationProfile = null;

    /**
     * Finalize a resumable upload by assembling all uploaded chunks.
     *
     * @param string $uploadId The unique upload identifier.
     * @return string The path to the final assembled file.
     * @throws UploadException If the upload directory is not set or chunks are missing.
     */
    public function finalizeChunkUpload(string $uploadId): string
    {
        if (!isset($this->uploadDir) || $this->uploadDir === '') {
            throw new UploadException('Upload directory is not set.');
        }

        $this->validateUploadId($uploadId);

        return $this->withChunkSessionLock($uploadId, function () use ($uploadId): string {
            [$manifest, $totalChunks] = $this->resolveCompleteChunkState($uploadId);
            $originalFilename = $manifest['originalFilename'];
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $this->validateFileExtension($extension);
            $chunkDirectory = $this->getChunkDirectory($uploadId);
            $stagingName = '.assembled_' . bin2hex(random_bytes(16));
            if ($extension !== '') {
                $stagingName .= '.' . ltrim($extension, '.');
            }
            $stagingPath = PathHelper::join($chunkDirectory, $stagingName);

            try {
                $this->mergeChunksToDestination($chunkDirectory, $totalChunks, $stagingPath);
                $this->validateFinalizedUpload($stagingPath);
                $destination = $this->finalizeIncomingFile($stagingPath, $extension);
            } catch (\Throwable $exception) {
                if (FlysystemHelper::fileExists($stagingPath)) {
                    FlysystemHelper::delete($stagingPath);
                }

                throw $exception;
            }

            $this->cleanupChunkUploadArtifacts($uploadId, $chunkDirectory);

            return $destination;
        });
    }

    /**
     * Get detailed info about the current configuration and settings.
     *
     * @return UploadInfo Array with upload configuration details.
     */
    public function getInfo(): array
    {
        return [
            'uploadDir' => $this->uploadDir ?? 'Not Set',
            'useDateDirectories' => $this->useDateDirectories,
            'tempDir' => $this->tempDir ?? sys_get_temp_dir(),
            'allowedFileTypes' => $this->allowedFileTypes,
            'allowedExtensions' => $this->allowedExtensions,
            'blockedExtensions' => $this->blockedExtensions,
            'maxFileSize' => $this->maxFileSize,
            'maxChunkCount' => $this->maxChunkCount,
            'maxChunkSize' => $this->maxChunkSize,
            'namingStrategy' => $this->namingStrategy,
            'validationProfile' => $this->validationProfile,
            'hasMalwareScanner' => is_callable($this->malwareScanner),
            'requireMalwareScan' => $this->requireMalwareScan,
            'strictContentTypeValidation' => $this->strictContentTypeValidation,
        ];
    }

    /**
     * Retrieve available validation profiles.
     *
     * @return list<string> List of available validation profile names.
     */
    public function getValidationProfiles(): array
    {
        return array_keys(self::VALIDATION_PROFILES);
    }

    /**
     * Ingest a trusted CLI/application file that is not an HTTP upload.
     *
     * @param array<string, mixed> $file File metadata using the same keys as $_FILES.
     * @param array<string, scalar|null> $metadata Explicit audit metadata for the log entry.
     */
    public function ingestFile(array $file, array $metadata = []): string
    {
        return $this->processIncomingFile($file, false, $metadata);
    }

    /**
     * Process an upload chunk and persist resumable state.
     *
     * @param array<string, mixed> $chunkFile The chunk file data from $_FILES.
     * @param string $uploadId The unique upload identifier.
     * @param int $chunkIndex The index of this chunk (0-based).
     * @param int $totalChunks Total number of chunks expected.
     * @param string $originalFilename The original filename.
     * @throws UploadException If the upload directory is not set.
     */
    public function processChunkUpload(
        array $chunkFile,
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $originalFilename,
    ): ChunkUploadState {
        if (!isset($this->uploadDir) || $this->uploadDir === '') {
            throw new UploadException('Upload directory is not set.');
        }
        $chunkFile = $this->validateFile($chunkFile);
        $this->validateChunkUploadRequest($chunkFile, $uploadId, $chunkIndex, $totalChunks, $originalFilename);

        return $this->withChunkSessionLock($uploadId, function () use (
            $chunkFile,
            $uploadId,
            $chunkIndex,
            $totalChunks,
            $originalFilename,
        ): ChunkUploadState {
            $chunkDirectory = $this->getChunkDirectory($uploadId);
            if (!FlysystemHelper::directoryExists($chunkDirectory)) {
                FlysystemHelper::createDirectory($chunkDirectory);
            }

            /** @var ChunkManifest $manifest */
            $manifest = $this->loadChunkManifest($uploadId) ?? [
                'uploadId' => $uploadId,
                'originalFilename' => $originalFilename,
                'totalChunks' => $totalChunks,
                'createdAt' => time(),
            ];
            $this->assertChunkManifestIdentity($manifest, $uploadId, $originalFilename, $totalChunks);
            $this->saveChunkManifest($uploadId, $manifest);

            $chunkPath = PathHelper::join($chunkDirectory, sprintf('chunk_%06d.part', $chunkIndex));
            $this->moveIncomingFile($chunkFile['tmp_name'], $chunkPath);
            $received = $this->receivedChunkMap($chunkDirectory, $totalChunks);

            return new ChunkUploadState(
                uploadId: $uploadId,
                receivedChunks: count($received),
                totalChunks: $totalChunks,
                complete: count($received) === $totalChunks,
            );
        });
    }

    /**
     * Process the upload and save the file.
     *
     * @param array<string, mixed> $file The file data from $_FILES.
     * @param array<string, scalar|null> $metadata Explicit audit metadata for the log entry.
     * @return string The path to the saved file.
     * @throws UploadException If validation fails or upload directory is not set.
     */
    public function processUpload(array $file, array $metadata = []): string
    {
        return $this->processIncomingFile($file, true, $metadata);
    }

    /**
     * Configure chunk upload constraints.
     *
     * @param int $maxChunkCount Maximum number of chunks allowed (0 for unlimited).
     * @param int $maxChunkSize Maximum size per chunk in bytes (0 for unlimited).
     */
    public function setChunkLimits(int $maxChunkCount = 0, int $maxChunkSize = 0): void
    {
        if ($maxChunkCount < 0 || $maxChunkSize < 0) {
            throw new UploadException('Chunk limits must be non-negative.');
        }
        $this->maxChunkCount = $maxChunkCount;
        $this->maxChunkSize = $maxChunkSize;
    }

    /**
     * Configure directory and path settings.
     *
     * @param string $uploadDir The upload directory path.
     * @param bool $useDateDirectories Whether to use date-based subdirectories.
     * @param string|null $tempDir The temporary directory path (null for system default).
     */
    public function setDirectorySettings(string $uploadDir, bool $useDateDirectories = false, ?string $tempDir = null): void
    {
        $this->uploadDir = PathHelper::normalize($this->validateDirectoryPath($uploadDir));
        $this->useDateDirectories = $useDateDirectories;
        $this->tempDir = $tempDir !== null
            ? PathHelper::normalize($this->validateDirectoryPath($tempDir))
            : sys_get_temp_dir();
        $this->ensureUploadDirectoryExists();
    }

    /**
     * Configure extension allow/block policy.
     *
     * @param list<string> $allowedExtensions
     * @param list<string> $blockedExtensions
     */
    public function setExtensionPolicy(array $allowedExtensions = [], array $blockedExtensions = []): void
    {
        $this->allowedExtensions = $this->normalizeExtensions($allowedExtensions);
        /** @var list<string> $defaultBlocked */
        $defaultBlocked = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com'];
        $this->blockedExtensions = $blockedExtensions === []
            ? $defaultBlocked
            : $this->normalizeExtensions($blockedExtensions);
    }

    /**
     * Configure optional image dimension validation.
     *
     * @param int $maxImageWidth Maximum image width in pixels (0 for unlimited).
     * @param int $maxImageHeight Maximum image height in pixels (0 for unlimited).
     */
    public function setImageValidationSettings(int $maxImageWidth = 0, int $maxImageHeight = 0): void
    {
        if ($maxImageWidth < 0 || $maxImageHeight < 0) {
            throw new UploadException('Image limits must be non-negative.');
        }
        $this->maxImageWidth = $maxImageWidth;
        $this->maxImageHeight = $maxImageHeight;
    }

    /**
     * Set the logger for upload operations.
     *
     * @param LoggerInterface $logger The logger instance.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Configure an optional malware scanner callback.
     *
     * Signature: fn(string $filePath, string $mimeType): bool
     *
     * @param callable $scanner The malware scanner callback.
     */
    public function setMalwareScanner(callable $scanner): void
    {
        $this->malwareScanner = $scanner;
    }

    /**
     * Configure naming strategy.
     *
     * @param string $namingStrategy The naming strategy ('hash' or 'timestamp').
     * @throws UploadException If an invalid strategy is specified.
     */
    public function setNamingStrategy(string $namingStrategy): void
    {
        if (!in_array($namingStrategy, ['hash', 'timestamp'], true)) {
            throw new UploadException("Invalid naming strategy: $namingStrategy.");
        }
        $this->namingStrategy = $namingStrategy;
    }

    /**
     * Require malware scanning before upload acceptance.
     *
     * @param bool $required If true, require malware scanning.
     */
    public function setRequireMalwareScan(bool $required = true): void
    {
        $this->requireMalwareScan = $required;
    }

    /**
     * Enable strict content checks (MIME-extension agreement + magic signature).
     *
     * @param bool $enabled If true, enable strict content type validation.
     */
    public function setStrictContentTypeValidation(bool $enabled = true): void
    {
        $this->strictContentTypeValidation = $enabled;
    }

    /**
     * Configure validation using a predefined profile.
     *
     * @param string $profile The validation profile name ('image', 'video', or 'document').
     * @throws UploadException If an invalid profile is specified.
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
     *
     * @param list<string> $allowedFileTypes Array of allowed MIME types.
     * @param int $maxFileSize Maximum file size in bytes.
     */
    public function setValidationSettings(array $allowedFileTypes, int $maxFileSize): void
    {
        if ($maxFileSize < 0) {
            throw new UploadException('Maximum file size must be non-negative.');
        }
        $this->allowedFileTypes = $allowedFileTypes;
        $this->maxFileSize = $maxFileSize;
        $this->validationProfile = null;
    }

    /**
     * Generate a unique file name based on the strategy and caller info.
     */
    private function generateFileName(?string $dataSource, string $extension): string
    {
        $identifier = match ($this->namingStrategy) {
            'timestamp' => sprintf('%d_%s', time(), bin2hex(random_bytes(8))),
            default => $dataSource !== null
                ? FlysystemHelper::checksum($dataSource, 'sha256')
                : bin2hex(random_bytes(32)),
        };
        if (!is_string($identifier)) {
            throw new UploadException('Unable to generate upload file name.');
        }

        $extension = ltrim($extension, '.');

        return $extension !== ''
            ? sprintf('upload_%s.%s', $identifier, $extension)
            : sprintf('upload_%s', $identifier);
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, scalar|null> $metadata
     */
    private function processIncomingFile(array $file, bool $requireHttpUpload, array $metadata): string
    {
        $logFileName = is_string($file['name'] ?? null) ? $file['name'] : null;

        try {
            if (!isset($this->uploadDir) || $this->uploadDir === '') {
                throw new UploadException('Upload directory is not set.');
            }

            $file = $this->validateFile($file);
            $tmpName = $file['tmp_name'];
            if ($requireHttpUpload && !is_uploaded_file($tmpName)) {
                throw new UploadException('File is not a valid HTTP upload.');
            }
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileType = $this->validateUploadedPayload($tmpName, $extension, false);

            $destination = $this->finalizeIncomingFile($tmpName, $extension);
            $fileName = basename($destination);

            if (isset($this->logger)) {
                $this->logger->info('File uploaded successfully.', [
                    'fileName' => $fileName,
                    'destination' => $destination,
                    'fileType' => $fileType,
                    'metadata' => $metadata,
                ]);
            }

            return $destination;
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->error('File upload failed.', [
                    'error' => $e->getMessage(),
                    'file' => $logFileName,
                    'metadata' => $metadata,
                ]);
            }

            throw $e;
        }
    }
}
