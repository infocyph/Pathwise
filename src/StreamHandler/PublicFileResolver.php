<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

use finfo;
use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use Infocyph\Pathwise\Results\PublicFileResolution;
use Infocyph\Pathwise\Security\LocalPathContainment;
use Infocyph\Pathwise\Utils\PathHelper;

final class PublicFileResolver
{
    public function resolve(
        string $trustedRoot,
        string $relativePath,
        PublicFileSymlinkPolicy $symlinkPolicy = PublicFileSymlinkPolicy::REJECT,
    ): PublicFileResolution {
        $root = $this->resolveTrustedRoot($trustedRoot);
        $relative = $this->normalizeRelativePath($relativePath);
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if ($symlinkPolicy === PublicFileSymlinkPolicy::REJECT && $this->containsSymbolicLink($root, $relative)) {
            throw new DownloadException('Public-file resolution rejected a symbolic link.');
        }

        $canonical = realpath($candidate);
        if (!is_string($canonical) || !is_file($canonical)) {
            throw new FileNotFoundException("Public file not found: {$relative}.");
        }
        if (!LocalPathContainment::isSameOrDescendant($root, $canonical)) {
            throw new DownloadException('Public-file path escaped the trusted root.');
        }

        $size = filesize($canonical);
        $lastModified = filemtime($canonical);
        if (!is_int($size) || !is_int($lastModified)) {
            throw new DownloadException('Unable to read public-file metadata.');
        }

        $mimeType = new finfo(FILEINFO_MIME_TYPE)->file($canonical);
        $mimeType = is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';

        clearstatcache(true, $candidate);
        $rechecked = realpath($candidate);
        if (
            !is_string($rechecked)
            || $rechecked !== $canonical
            || !is_file($rechecked)
            || !LocalPathContainment::isSameOrDescendant($root, $rechecked)
        ) {
            throw new DownloadException('Public file changed during trust resolution.');
        }

        return new PublicFileResolution(
            path: $canonical,
            relativePath: $relative,
            mimeType: $mimeType,
            size: $size,
            lastModified: $lastModified,
        );
    }

    private function containsSymbolicLink(string $root, string $relativePath): bool
    {
        $candidate = $root;
        foreach (explode('/', $relativePath) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRelativePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || PathHelper::hasScheme($path)) {
            throw new DownloadException('Public-file path must be a non-empty relative filesystem path.');
        }

        $normalized = str_replace('\\', '/', $path);
        if (
            str_starts_with($normalized, '/')
            || str_starts_with($normalized, '//')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
        ) {
            throw new DownloadException('Public-file path must be relative to the trusted root.');
        }

        $segments = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new DownloadException('Public-file traversal is not allowed.');
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new DownloadException('Public-file path must identify a file below the trusted root.');
        }

        return implode('/', $segments);
    }

    private function resolveTrustedRoot(string $root): string
    {
        if ($root === '' || str_contains($root, "\0") || PathHelper::hasScheme($root)) {
            throw new DownloadException('Trusted public root must be an existing local directory.');
        }

        $canonical = realpath($root);
        if (!is_string($canonical) || !is_dir($canonical)) {
            throw new DownloadException('Trusted public root must be an existing local directory.');
        }

        return $canonical;
    }
}
