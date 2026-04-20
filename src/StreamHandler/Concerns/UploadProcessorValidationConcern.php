<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Concerns;

use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\MetadataHelper;
use Infocyph\Pathwise\Utils\PathHelper;

/**
 * @phpstan-type UploadInput array{
 *     error: int,
 *     size: int|numeric-string,
 *     tmp_name: string,
 *     name: string
 * }
 */
trait UploadProcessorValidationConcern
{
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
            $this->unlinkFileSilently($tempFile);

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

                $this->unlinkFileSilently($source);

                return;
            }

            throw new UploadException('Failed to move uploaded file.');
        }

        if (PathHelper::hasScheme($source) || PathHelper::hasScheme($destination)) {
            try {
                FlysystemHelper::copy($source, $destination);
            } catch (\Throwable) {
                throw new UploadException('Failed to move incoming file.');
            }

            FlysystemHelper::delete($source);

            return;
        }

        if (!$this->runSilently(static fn(): bool => rename($source, $destination))) {
            try {
                FlysystemHelper::copy($source, $destination);
            } catch (\Throwable) {
                throw new UploadException('Failed to move incoming file.');
            }
            FlysystemHelper::delete($source);
        }
    }

    private function normalizeExtension(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
    }

    /**
     * @param array<int, string> $extensions
     * @return list<string>
     */
    private function normalizeExtensions(array $extensions): array
    {
        $normalized = [];
        foreach ($extensions as $extension) {
            $candidate = $this->normalizeExtension($extension);
            if ($candidate === '') {
                continue;
            }
            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeUploadSize(int|string $size): int
    {
        if (is_int($size)) {
            return $size;
        }

        if (!is_numeric($size)) {
            throw new UploadException('Invalid upload size metadata.');
        }

        return (int) $size;
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

    private function readHeaderBytes(string $filePath, int $length): ?string
    {
        $length = max(1, $length);
        $stream = FlysystemHelper::readStream($filePath);
        if (!is_resource($stream)) {
            return null;
        }

        try {
            $bytes = fread($stream, $length);
        } finally {
            fclose($stream);
        }

        return is_string($bytes) ? $bytes : null;
    }

    private function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Sanitize a path to remove invalid characters.
     */
    private function sanitizePath(string $path): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9\/\\\\:_.-]/', '', $path) ?? '';

        return rtrim($sanitized, '/\\');
    }

    private function scanForMalware(string $filePath, string $fileType): void
    {
        if (!is_callable($this->malwareScanner)) {
            if ($this->requireMalwareScan) {
                throw new UploadException('Malware scanner is required but not configured.');
            }

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

    private function unlinkFileSilently(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $this->runSilently(static fn(): bool => unlink($path));
    }

    private function validateContentTypeIntegrity(string $filePath, string $fileType, string $extension): void
    {
        if (!$this->strictContentTypeValidation) {
            return;
        }

        $normalizedExtension = $this->normalizeExtension($extension);
        if ($normalizedExtension === '') {
            return;
        }

        $this->validateMimeTypeMatchesExtension($fileType, $normalizedExtension);
        $this->validateMagicSignatureForExtension($filePath, $normalizedExtension);
    }

    /**
     * Validate the uploaded file.
     */
    /**
     * @param array<string, mixed> $file
     */
    private function validateFile(array $file): void
    {
        $error = $file['error'] ?? null;
        if (!is_int($error)) {
            throw new UploadException('Invalid file upload parameters.');
        }

        switch ($error) {
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

        $size = $file['size'] ?? null;
        $tmpName = $file['tmp_name'] ?? null;
        $name = $file['name'] ?? null;
        if ((!is_int($size) && !is_string($size)) || !is_string($tmpName) || !is_string($name)) {
            throw new UploadException('Invalid file upload parameters.');
        }

        $this->validateFileSize($this->normalizeUploadSize($size));
    }

    private function validateFileExtension(string $extension): void
    {
        $normalized = $this->normalizeExtension($extension);
        if ($normalized === '') {
            if ($this->allowedExtensions !== []) {
                throw new UploadException('File extension is required.');
            }

            return;
        }

        if (in_array($normalized, $this->blockedExtensions, true)) {
            throw new UploadException('Blocked file extension.');
        }

        if ($this->allowedExtensions !== [] && !in_array($normalized, $this->allowedExtensions, true)) {
            throw new UploadException('File extension is not allowed.');
        }
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
        $extension = pathinfo($destination, PATHINFO_EXTENSION);
        $this->validateFileExtension($extension);
        $this->validateFileType($fileType);
        $this->validateContentTypeIntegrity($destination, $fileType, $extension);
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
                $this->unlinkFileSilently($pathForInspection);
            }
        }

        if ($dimensions === false) {
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

    private function validateMagicSignatureForExtension(string $filePath, string $extension): void
    {
        $header = $this->readHeaderBytes($filePath, 16);
        if ($header === null) {
            throw new UploadException('Unable to inspect file signature.');
        }

        $matchesSignature = match ($extension) {
            'jpg', 'jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            'png' => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
            'gif' => str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a'),
            'webp' => str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP',
            'pdf' => str_starts_with($header, '%PDF-'),
            'zip', 'docx' => str_starts_with($header, "PK\x03\x04")
                || str_starts_with($header, "PK\x05\x06")
                || str_starts_with($header, "PK\x07\x08"),
            default => true,
        };

        if (!$matchesSignature) {
            throw new UploadException('File signature does not match extension.');
        }
    }

    private function validateMimeTypeMatchesExtension(string $fileType, string $extension): void
    {
        $allowedMimes = match ($extension) {
            'jpg', 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain', 'application/octet-stream'],
            'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream'],
            'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'mp4' => ['video/mp4', 'application/octet-stream'],
            'webm' => ['video/webm', 'application/octet-stream'],
            'mov', 'qt' => ['video/quicktime', 'application/octet-stream'],
            default => [],
        };

        if ($allowedMimes === []) {
            return;
        }

        $normalizedMime = strtolower(trim(explode(';', $fileType, 2)[0]));
        if (!in_array($normalizedMime, $allowedMimes, true)) {
            throw new UploadException('File content type does not match extension.');
        }
    }
}
