<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

use Infocyph\Pathwise\Exceptions\UploadException;
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
 *     received: array<int|string, string>,
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

    private int $maxFileSize = 30720;

    private int $maxImageHeight = 0;

    private int $maxImageWidth = 0;

    private string $namingStrategy = 'hash';

    private bool $requireMalwareScan = false;

    private bool $strictContentTypeValidation = false;

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
        if (empty($this->uploadDir)) {
            throw new UploadException('Upload directory is not set.');
        }

        $this->validateUploadId($uploadId);
        [$manifest, $totalChunks, $received] = $this->resolveCompleteChunkState($uploadId);
        $originalFilename = $manifest['originalFilename'];
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $this->validateFileExtension($extension);
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
     * Process an upload chunk and persist resumable state.
     *
     * @param UploadInput $chunkFile The chunk file data from $_FILES.
     * @param string $uploadId The unique upload identifier.
     * @param int $chunkIndex The index of this chunk (0-based).
     * @param int $totalChunks Total number of chunks expected.
     * @param string $originalFilename The original filename.
     * @return array{uploadId: string, receivedChunks: int, totalChunks: int, isComplete: bool} Chunk upload status.
     * @throws UploadException If the upload directory is not set.
     */
    public function processChunkUpload(array $chunkFile, string $uploadId, int $chunkIndex, int $totalChunks, string $originalFilename): array
    {
        if (empty($this->uploadDir)) {
            throw new UploadException('Upload directory is not set.');
        }
        $this->validateChunkUploadRequest($chunkFile, $uploadId, $chunkIndex, $totalChunks, $originalFilename);
        $this->validateFile($chunkFile);

        $chunkDirectory = $this->getChunkDirectory($uploadId);
        if (!FlysystemHelper::directoryExists($chunkDirectory)) {
            FlysystemHelper::createDirectory($chunkDirectory);
        }

        $chunkPath = PathHelper::join($chunkDirectory, sprintf('chunk_%06d.part', $chunkIndex));
        $this->moveIncomingFile($chunkFile['tmp_name'], $chunkPath);

        /** @var ChunkManifest $manifest */
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
     *
     * @param UploadInput $file The file data from $_FILES.
     * @return string The path to the saved file.
     * @throws UploadException If validation fails or upload directory is not set.
     */
    public function processUpload(array $file): string
    {
        try {
            if (empty($this->uploadDir)) {
                throw new UploadException('Upload directory is not set.');
            }

            $this->validateFile($file);
            $tmpName = $file['tmp_name'];
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $this->validateFileExtension($extension);

            $fileType = $this->getFileMimeType($tmpName);
            $this->validateFileType($fileType);
            $this->validateContentTypeIntegrity($tmpName, $fileType, $extension);

            if ($this->isImage($fileType)) {
                $this->validateImageDimensions($tmpName);
            }
            $this->scanForMalware($tmpName, $fileType);

            $fileName = $this->generateFileName($tmpName, $extension);
            $destination = $this->getUniqueDestination($fileName);

            if (!move_uploaded_file($tmpName, $destination)) {
                $stream = fopen($tmpName, 'rb');
                if (!is_resource($stream)) {
                    throw new UploadException('Failed to move uploaded file.');
                }

                try {
                    FlysystemHelper::writeStream($destination, $stream);
                } finally {
                    fclose($stream);
                }

                $this->unlinkFileSilently($tmpName);
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
                    'file' => $file['name'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * Configure chunk upload constraints.
     *
     * @param int $maxChunkCount Maximum number of chunks allowed (0 for unlimited).
     * @param int $maxChunkSize Maximum size per chunk in bytes (0 for unlimited).
     */
    public function setChunkLimits(int $maxChunkCount = 0, int $maxChunkSize = 0): void
    {
        $this->maxChunkCount = max(0, $maxChunkCount);
        $this->maxChunkSize = max(0, $maxChunkSize);
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
        $this->uploadDir = PathHelper::normalize($this->sanitizePath($uploadDir));
        $this->useDateDirectories = $useDateDirectories;
        $this->tempDir = $tempDir
            ? PathHelper::normalize($this->sanitizePath($tempDir))
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
        $this->maxImageWidth = max(0, $maxImageWidth);
        $this->maxImageHeight = max(0, $maxImageHeight);
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
        $this->allowedFileTypes = $allowedFileTypes;
        $this->maxFileSize = $maxFileSize;
        $this->validationProfile = null;
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
}
