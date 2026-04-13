<?php

namespace Infocyph\Pathwise\StreamHandler;

use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\MetadataHelper;
use Infocyph\Pathwise\Utils\PathHelper;

class DownloadProcessor
{
    private array $allowedExtensions = [];
    private array $allowedRoots = [];
    private array $blockedExtensions = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com'];
    private bool $blockHiddenFiles = true;
    private int $chunkSize = 8192;
    private string $defaultDownloadName = 'download.bin';
    private bool $forceAttachment = true;
    private int $maxDownloadSize = 0;
    private bool $rangeRequestsEnabled = true;

    /**
     * Build secure download metadata for a file path.
     *
     * @return array{
     *   path: string,
     *   fileName: string,
     *   mimeType: string,
     *   size: int,
     *   lastModified: int,
     *   etag: string,
     *   status: int,
     *   rangeStart: int,
     *   rangeEnd: int,
     *   contentLength: int,
     *   headers: array<string, string>
     * }
     */
    public function prepareDownload(string $path, ?string $downloadName = null, ?string $rangeHeader = null): array
    {
        $normalizedPath = PathHelper::normalize($path);
        $this->validateDownloadPath($normalizedPath);

        $size = FlysystemHelper::size($normalizedPath);
        if ($this->maxDownloadSize > 0 && $size > $this->maxDownloadSize) {
            throw new FileSizeExceededException('Download exceeds configured size limit.');
        }

        $extension = pathinfo($normalizedPath, PATHINFO_EXTENSION);
        $this->validateExtension($extension);

        $mimeType = MetadataHelper::getMimeType($normalizedPath) ?? 'application/octet-stream';
        $lastModified = FlysystemHelper::lastModified($normalizedPath);
        [$rangeStart, $rangeEnd, $isPartial] = $this->resolveRange($rangeHeader, $size);
        $contentLength = ($rangeEnd - $rangeStart) + 1;

        $resolvedFileName = $this->resolveDownloadName($downloadName, $normalizedPath);
        $disposition = $this->forceAttachment ? 'attachment' : 'inline';
        $etag = $this->buildEtag($normalizedPath, $size, $lastModified);

        $headers = [
            'Accept-Ranges' => $this->rangeRequestsEnabled ? 'bytes' : 'none',
            'Cache-Control' => 'private, no-transform',
            'Content-Disposition' => $this->buildContentDisposition($disposition, $resolvedFileName),
            'Content-Length' => (string) $contentLength,
            'Content-Type' => $mimeType,
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($isPartial) {
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $rangeStart, $rangeEnd, $size);
        }

        return [
            'path' => $normalizedPath,
            'fileName' => $resolvedFileName,
            'mimeType' => $mimeType,
            'size' => $size,
            'lastModified' => $lastModified,
            'etag' => $etag,
            'status' => $isPartial ? 206 : 200,
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'contentLength' => $contentLength,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<int, string> $roots
     */
    public function setAllowedRoots(array $roots): void
    {
        $this->allowedRoots = [];
        foreach ($roots as $root) {
            $candidate = PathHelper::normalize($root);
            if ($candidate !== '') {
                $this->allowedRoots[] = $candidate;
            }
        }
    }

    public function setBlockHiddenFiles(bool $block = true): void
    {
        $this->blockHiddenFiles = $block;
    }

    public function setChunkSize(int $chunkSize): void
    {
        $this->chunkSize = max(1024, $chunkSize);
    }

    public function setDefaultDownloadName(string $name): void
    {
        $safe = $this->sanitizeFilename($name);
        $this->defaultDownloadName = $safe !== '' ? $safe : 'download.bin';
    }

    /**
     * @param array<int, string> $allowedExtensions
     * @param array<int, string> $blockedExtensions
     */
    public function setExtensionPolicy(array $allowedExtensions = [], array $blockedExtensions = []): void
    {
        $this->allowedExtensions = $this->normalizeExtensions($allowedExtensions);
        $this->blockedExtensions = $blockedExtensions === []
            ? ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com']
            : $this->normalizeExtensions($blockedExtensions);
    }

    public function setForceAttachment(bool $enabled = true): void
    {
        $this->forceAttachment = $enabled;
    }

    public function setMaxDownloadSize(int $maxDownloadSize = 0): void
    {
        $this->maxDownloadSize = max(0, $maxDownloadSize);
    }

    public function setRangeRequestsEnabled(bool $enabled = true): void
    {
        $this->rangeRequestsEnabled = $enabled;
    }

    /**
     * Stream a secure download to a writable resource and return the manifest.
     *
     * @return array{
     *   path: string,
     *   fileName: string,
     *   mimeType: string,
     *   size: int,
     *   lastModified: int,
     *   etag: string,
     *   status: int,
     *   rangeStart: int,
     *   rangeEnd: int,
     *   contentLength: int,
     *   bytesSent: int,
     *   headers: array<string, string>
     * }
     */
    public function streamDownload(
        string $path,
        mixed $outputStream,
        ?string $downloadName = null,
        ?string $rangeHeader = null,
    ): array {
        if (!is_resource($outputStream)) {
            throw new DownloadException('Invalid output stream.');
        }

        $manifest = $this->prepareDownload($path, $downloadName, $rangeHeader);

        $inputStream = FlysystemHelper::readStream($manifest['path']);
        if (!is_resource($inputStream)) {
            throw new DownloadException('Unable to open input stream for download.');
        }

        try {
            $this->seekStreamToOffset($inputStream, $manifest['rangeStart']);

            $remaining = $manifest['contentLength'];
            $bytesSent = 0;
            while ($remaining > 0) {
                $chunk = fread($inputStream, min($this->chunkSize, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }

                $written = $this->writeFully($outputStream, $chunk);
                $bytesSent += $written;
                $remaining -= $written;
            }
        } finally {
            fclose($inputStream);
        }

        if ($bytesSent !== $manifest['contentLength']) {
            throw new DownloadException('Incomplete download stream copy.');
        }

        $manifest['bytesSent'] = $bytesSent;

        return $manifest;
    }

    private function buildContentDisposition(string $disposition, string $fileName): string
    {
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $fileName) ?? 'download.bin';
        $asciiFallback = str_replace(['\\', '"'], '_', $asciiFallback);
        $asciiFallback = trim($asciiFallback);
        if ($asciiFallback === '') {
            $asciiFallback = 'download.bin';
        }

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $asciiFallback,
            rawurlencode($fileName),
        );
    }

    private function buildEtag(string $path, int $size, int $lastModified): string
    {
        $fingerprint = substr(hash('sha1', $path), 0, 8);

        return sprintf('W/"%x-%x-%s"', $size, $lastModified, $fingerprint);
    }

    private function discardBytes(mixed $stream, int $bytes): void
    {
        $remaining = $bytes;
        while ($remaining > 0 && !feof($stream)) {
            $chunk = fread($stream, min($this->chunkSize, $remaining));
            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $remaining -= strlen($chunk);
        }

        if ($remaining > 0) {
            throw new DownloadException('Unable to seek download stream to requested range start.');
        }
    }

    private function isHiddenFile(string $path): bool
    {
        $basename = basename($path);

        return $basename !== '' && str_starts_with($basename, '.');
    }

    private function normalizeExtension(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
    }

    /**
     * @param array<int, string> $extensions
     * @return array<int, string>
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

    private function pathStartsWith(string $path, string $prefix): bool
    {
        if ($path === $prefix) {
            return true;
        }

        $pathNormalized = rtrim($path, '/\\');
        $prefixNormalized = rtrim($prefix, '/\\');
        if ($pathNormalized === $prefixNormalized) {
            return true;
        }

        $needle = $prefixNormalized . DIRECTORY_SEPARATOR;
        if (PHP_OS_FAMILY === 'Windows') {
            return str_starts_with(strtolower($pathNormalized), strtolower($needle));
        }

        return str_starts_with($pathNormalized, $needle);
    }

    private function pathWithinAllowedRoot(string $path): bool
    {
        if ($this->allowedRoots === []) {
            return true;
        }

        $pathIsScheme = PathHelper::hasScheme($path);
        foreach ($this->allowedRoots as $root) {
            $rootIsScheme = PathHelper::hasScheme($root);
            if ($pathIsScheme || $rootIsScheme) {
                if (!$pathIsScheme || !$rootIsScheme) {
                    continue;
                }

                $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
                $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
                if ($normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . '/')) {
                    return true;
                }

                continue;
            }

            $pathAbsolute = PathHelper::isAbsolute($path)
                ? $path
                : PathHelper::toAbsolutePath($path);
            $rootAbsolute = PathHelper::isAbsolute($root)
                ? $root
                : PathHelper::toAbsolutePath($root);

            $resolvedPath = realpath($pathAbsolute) ?: PathHelper::normalize($pathAbsolute);
            $resolvedRoot = realpath($rootAbsolute) ?: PathHelper::normalize($rootAbsolute);
            if ($this->pathStartsWith($resolvedPath, $resolvedRoot)) {
                return true;
            }
        }

        return false;
    }

    private function resolveDownloadName(?string $downloadName, string $path): string
    {
        $fallback = basename($path);
        $candidate = $downloadName ?? $fallback;
        $safe = $this->sanitizeFilename($candidate);
        if ($safe === '') {
            $safe = $this->sanitizeFilename($fallback);
        }

        return $safe !== '' ? $safe : $this->defaultDownloadName;
    }

    /**
     * @return array{int, int, bool}
     */
    private function resolveRange(?string $rangeHeader, int $size): array
    {
        if ($size < 1) {
            throw new DownloadException('Cannot prepare download for empty file.');
        }

        if (!$this->rangeRequestsEnabled || $rangeHeader === null || trim($rangeHeader) === '') {
            return [0, $size - 1, false];
        }

        if (preg_match('/^\s*bytes=(\d*)-(\d*)\s*$/', $rangeHeader, $matches) !== 1) {
            throw new DownloadException('Invalid range header.');
        }

        $startRaw = $matches[1] ?? '';
        $endRaw = $matches[2] ?? '';

        if ($startRaw === '' && $endRaw === '') {
            throw new DownloadException('Invalid range header.');
        }

        if ($startRaw === '') {
            $suffixLength = (int) $endRaw;
            if ($suffixLength <= 0) {
                throw new DownloadException('Invalid range header.');
            }

            $start = max(0, $size - $suffixLength);
            $end = $size - 1;

            return [$start, $end, true];
        }

        $start = (int) $startRaw;
        if ($start < 0 || $start >= $size) {
            throw new DownloadException('Invalid range header.');
        }

        if ($endRaw === '') {
            return [$start, $size - 1, true];
        }

        $end = (int) $endRaw;
        if ($end < $start) {
            throw new DownloadException('Invalid range header.');
        }

        return [$start, min($end, $size - 1), true];
    }

    private function sanitizeFilename(string $name): string
    {
        $candidate = trim($name);
        if ($candidate === '' || str_contains($candidate, "\0")) {
            return '';
        }

        $candidate = preg_replace('/[\/\\\\]+/', '_', $candidate) ?? '';
        $candidate = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/', '', $candidate) ?? '';
        $candidate = trim($candidate, " .\t\n\r\0\x0B");
        if ($candidate === '' || $candidate === '.' || $candidate === '..') {
            return '';
        }

        if (strlen($candidate) > 255) {
            $extension = pathinfo($candidate, PATHINFO_EXTENSION);
            $filename = pathinfo($candidate, PATHINFO_FILENAME);
            if ($extension !== '') {
                $maxFilenameLength = max(1, 255 - strlen($extension) - 1);
                $candidate = substr($filename, 0, $maxFilenameLength) . '.' . $extension;
            } else {
                $candidate = substr($candidate, 0, 255);
            }
        }

        return $candidate;
    }

    private function seekStreamToOffset(mixed $stream, int $offset): void
    {
        if ($offset < 1) {
            return;
        }

        $metadata = stream_get_meta_data($stream);
        $seekable = is_array($metadata) && ($metadata['seekable'] ?? false);
        if ($seekable && @fseek($stream, $offset, SEEK_SET) === 0) {
            return;
        }

        $this->discardBytes($stream, $offset);
    }

    private function validateDownloadPath(string $path): void
    {
        if (!FlysystemHelper::fileExists($path)) {
            throw new FileNotFoundException("File not found at {$path}.");
        }

        if ($this->blockHiddenFiles && $this->isHiddenFile($path)) {
            throw new DownloadException('Hidden file downloads are blocked.');
        }

        if (!$this->pathWithinAllowedRoot($path)) {
            throw new DownloadException('Download path is outside allowed roots.');
        }
    }

    private function validateExtension(string $extension): void
    {
        $normalized = $this->normalizeExtension($extension);
        if ($normalized === '') {
            if ($this->allowedExtensions !== []) {
                throw new DownloadException('File extension is required for download.');
            }

            return;
        }

        if (in_array($normalized, $this->blockedExtensions, true)) {
            throw new DownloadException('Blocked file extension for download.');
        }

        if ($this->allowedExtensions !== [] && !in_array($normalized, $this->allowedExtensions, true)) {
            throw new DownloadException('File extension is not allowed for download.');
        }
    }

    private function writeFully(mixed $stream, string $payload): int
    {
        $totalWritten = 0;
        $payloadLength = strlen($payload);
        while ($totalWritten < $payloadLength) {
            $chunk = substr($payload, $totalWritten);
            $written = fwrite($stream, $chunk);
            if (!is_int($written) || $written <= 0) {
                throw new DownloadException('Failed to write to output stream.');
            }

            $totalWritten += $written;
        }

        return $totalWritten;
    }
}
