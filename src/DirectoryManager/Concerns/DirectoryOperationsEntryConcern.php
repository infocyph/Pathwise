<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\DirectoryManager\Concerns;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Pathwise\Utils\PermissionsHelper;

/**
 * @phpstan-type StorageEntry array<string, mixed>
 * @phpstan-type DetailedContentItem array{
 *     path: string,
 *     type: string,
 *     size: int,
 *     permissions: string|null,
 *     last_modified: int
 * }
 * @phpstan-type FindCriteria array{
 *     name?: string,
 *     extension?: string,
 *     permissions?: int,
 *     minSize?: int,
 *     maxSize?: int
 * }
 */
trait DirectoryOperationsEntryConcern
{
    /**
     * @param StorageEntry $item
     * @return DetailedContentItem
     */
    private function buildDetailedContentItem(string $resolvedPath, array $item): array
    {
        $permissions = null;
        if ($this->isLocalPath($resolvedPath) && file_exists($resolvedPath)) {
            $permissionBits = fileperms($resolvedPath);
            if (is_int($permissionBits)) {
                $permissions = PermissionsHelper::formatPermissions($permissionBits);
            }
        }

        return [
            'path' => $resolvedPath,
            'type' => $this->entryType($item),
            'size' => $this->entrySize($item),
            'permissions' => $permissions ?? $this->entryVisibility($item),
            'last_modified' => $this->entryLastModified($item),
        ];
    }

    private function buildPath(string $basePath, string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return PathHelper::normalize($basePath);
        }

        if (PathHelper::hasScheme($basePath)) {
            return rtrim(str_replace('\\', '/', $basePath), '/') . '/' . $relativePath;
        }

        return PathHelper::join($basePath, $relativePath);
    }

    private function ensureDirectoryExists(string $path): string
    {
        $path = PathHelper::normalize($path);
        if (!FlysystemHelper::directoryExists($path)) {
            FlysystemHelper::createDirectory($path);
        }

        return $path;
    }

    /**
     * @param StorageEntry $item
     */
    private function entryLastModified(array $item): int
    {
        $lastModified = $item['last_modified'] ?? 0;
        if (is_int($lastModified)) {
            return $lastModified;
        }

        return is_numeric($lastModified) ? (int) $lastModified : 0;
    }

    /**
     * @param StorageEntry $item
     */
    private function entryPath(array $item): string
    {
        $path = $item['path'] ?? '';

        return is_string($path) ? $path : '';
    }

    /**
     * @param StorageEntry $item
     */
    private function entrySize(array $item): int
    {
        $size = $item['file_size'] ?? 0;
        if (is_int($size)) {
            return $size;
        }

        return is_numeric($size) ? (int) $size : 0;
    }

    /**
     * @param StorageEntry $item
     */
    private function entryType(array $item): string
    {
        $type = $item['type'] ?? 'file';

        return is_string($type) ? $type : 'file';
    }

    /**
     * @param StorageEntry $item
     */
    private function entryVisibility(array $item): string
    {
        $visibility = $item['visibility'] ?? '';

        return is_string($visibility) ? $visibility : '';
    }

    /**
     * @param StorageEntry $metadata
     */
    private function invokeFilter(?callable $filter, string $path, array $metadata): bool
    {
        if ($filter === null) {
            return true;
        }

        try {
            return (bool) $filter($path, $metadata);
        } catch (\ArgumentCountError) {
            return (bool) $filter($path);
        }
    }

    private function isLocalPath(string $path): bool
    {
        return !PathHelper::hasScheme($path) && PathHelper::isAbsolute($path);
    }

    /**
     * @return list<StorageEntry>
     */
    private function listStorageEntries(string $path, bool $deep): array
    {
        $entries = [];
        foreach (FlysystemHelper::listContents($path, $deep) as $item) {
            $entries[] = $item;
        }

        return $entries;
    }

    /**
     * @param FindCriteria $criteria
     */
    private function matchesFindCriteria(array $criteria, string $resolvedPath, int $size, bool $isWindows): bool
    {
        return (empty($criteria['name']) || str_contains(basename($resolvedPath), $criteria['name']))
            && (empty($criteria['extension']) || pathinfo($resolvedPath, PATHINFO_EXTENSION) === $criteria['extension'])
            && $this->matchesPermissionsCriteria($criteria, $resolvedPath, $isWindows)
            && (empty($criteria['minSize']) || $size >= $criteria['minSize'])
            && (empty($criteria['maxSize']) || $size <= $criteria['maxSize']);
    }

    /**
     * @param FindCriteria $criteria
     */
    private function matchesPermissionsCriteria(array $criteria, string $resolvedPath, bool $isWindows): bool
    {
        if (empty($criteria['permissions']) || $isWindows) {
            return true;
        }

        if (!$this->isLocalPath($resolvedPath) || !file_exists($resolvedPath)) {
            return false;
        }

        $permissions = fileperms($resolvedPath);

        return is_int($permissions) && ($permissions & 0777) === $criteria['permissions'];
    }

    private function relativeStoragePath(string $baseLocation, string $itemPath): string
    {
        $normalizedBase = trim(str_replace('\\', '/', $baseLocation), '/');
        $normalizedPath = trim(str_replace('\\', '/', $itemPath), '/');

        if ($normalizedBase === '') {
            return $normalizedPath;
        }

        if ($normalizedPath === $normalizedBase) {
            return '';
        }

        if (str_starts_with($normalizedPath, $normalizedBase . '/')) {
            return substr($normalizedPath, strlen($normalizedBase) + 1);
        }

        return $normalizedPath;
    }

    private function storageLocation(string $directoryPath): string
    {
        [, $location] = FlysystemHelper::resolveDirectory($directoryPath);

        return trim(str_replace('\\', '/', $location), '/');
    }
}
