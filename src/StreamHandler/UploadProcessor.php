<?php

namespace Infocyph\Pathwise\StreamHandler;

use Psr\Log\LoggerInterface;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\Exceptions\FileSizeExceededException;

class UploadProcessor
{
    private string $uploadDir;
    private array $allowedFileTypes = [];
    private int $maxFileSize = 30720;
    private string $namingStrategy = 'hash';
    private bool $useDateDirectories = false;
    private ?string $tempDir = null;
    private LoggerInterface $logger;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Configure directory and path settings.
     */
    public function setDirectorySettings(string $uploadDir, bool $useDateDirectories = false, ?string $tempDir = null): void
    {
        $this->uploadDir = rtrim($this->sanitizePath($uploadDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->useDateDirectories = $useDateDirectories;
        $this->tempDir = $tempDir ? rtrim($this->sanitizePath($tempDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : sys_get_temp_dir();
        $this->ensureUploadDirectoryExists();
    }

    /**
     * Configure validation settings.
     */
    public function setValidationSettings(array $allowedFileTypes, int $maxFileSize): void
    {
        $this->allowedFileTypes = $allowedFileTypes;
        $this->maxFileSize = $maxFileSize;
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

            $fileName = $this->generateFileName($file['tmp_name'], pathinfo($file['name'], PATHINFO_EXTENSION));
            $destination = $this->getUniqueDestination($fileName);

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new UploadException('Failed to move uploaded file.');
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
        } catch (\RuntimeException $e) {
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
     * Get the MIME type of a file.
     */
    private function getFileMimeType(string $filePath): string
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        return $fileInfo->file($filePath);
    }

    /**
     * Validate the file type.
     */
    private function validateFileType(string $fileType): void
    {
        if (!in_array($fileType, $this->allowedFileTypes, true)) {
            throw new UploadException('Invalid file format.');
        }
    }

    /**
     * Validate image dimensions.
     */
    private function validateImageDimensions(string $filePath): void
    {
        [$width, $height] = getimagesize($filePath);
        if ($this->maxImageWidth > 0 && $width > $this->maxImageWidth) {
            throw new UploadException('Image width exceeds the maximum allowed.');
        }
        if ($this->maxImageHeight > 0 && $height > $this->maxImageHeight) {
            throw new UploadException('Image height exceeds the maximum allowed.');
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

        $classPrefix = strtolower(preg_replace('/[a-z]+/', '', (new \ReflectionClass($callingClass))->getShortName()));
        $methodPrefix = strtolower(
            preg_replace('/[a-z]+/', '', $callingMethod) ?: preg_replace('/[aeiou]/', '', $callingMethod),
        );

        $prefix = "{$classPrefix}_$methodPrefix";

        $hash = match ($this->namingStrategy) {
            'timestamp' => time(),
            default => $dataSource ? sha1_file($dataSource) : sha1(uniqid('', true)),
        };

        return sprintf('%s_%s.%s', $prefix, $hash, $extension);
    }

    /**
     * Get a unique destination for the uploaded file.
     */
    private function getUniqueDestination(string $fileName): string
    {
        $subDir = $this->useDateDirectories ? date('Y/m/d/') : '';
        $destinationDir = $this->uploadDir . $subDir;

        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true)) {
            throw new UploadException('Failed to create upload subdirectory.');
        }

        $destination = $destinationDir . $fileName;

        if (file_exists($destination)) {
            throw new UploadException('File with the same name already exists.');
        }

        return $destination;
    }

    /**
     * Ensure the upload directory exists.
     */
    private function ensureUploadDirectoryExists(): void
    {
        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true)) {
            throw new UploadException('Failed to create upload directory.');
        }
    }

    /**
     * Sanitize a path to remove invalid characters.
     */
    private function sanitizePath(string $path): string
    {
        return rtrim(preg_replace('/[^a-zA-Z0-9\/_.-]/', '', $path), '/');
    }

    /**
     * Check if a file is an image.
     */
    private function isImage(string $fileType): bool
    {
        return str_starts_with($fileType, 'image/');
    }
}
