<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Security;

use Infocyph\Pathwise\Utils\PathHelper;

/**
 * Canonical containment for direct-local filesystem security decisions.
 *
 * @internal
 */
final class LocalPathContainment
{
    public static function isSameOrDescendant(string $root, string $candidate): bool
    {
        $canonicalRoot = self::canonicalPath($root);
        $canonicalCandidate = self::canonicalPath($candidate);
        if ($canonicalRoot === null || $canonicalCandidate === null) {
            return false;
        }

        $rootKey = self::comparisonKey($canonicalRoot);
        $candidateKey = self::comparisonKey($canonicalCandidate);
        if ($candidateKey === $rootKey) {
            return true;
        }

        return str_starts_with($candidateKey, rtrim($rootKey, '/') . '/');
    }

    private static function canonicalPath(string $path): ?string
    {
        $absolute = self::normalizeAbsolutePath($path);
        if ($absolute === null) {
            return null;
        }

        $suffix = [];
        $current = $absolute;
        while (!file_exists($current) && !is_link($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }

            array_unshift($suffix, basename($current));
            $current = $parent;
        }

        $resolved = realpath($current);
        if (!is_string($resolved) || $resolved === '') {
            return null;
        }

        foreach ($suffix as $segment) {
            $resolved .= DIRECTORY_SEPARATOR . $segment;
        }

        return self::normalizeAbsolutePath($resolved);
    }

    private static function comparisonKey(string $path): string
    {
        $key = rtrim(str_replace('\\', '/', $path), '/');
        if ($key === '') {
            $key = '/';
        }

        return PHP_OS_FAMILY === 'Windows' ? strtolower($key) : $key;
    }

    /** @param list<string> $segments */
    private static function collapseSegments(array $segments): array
    {
        $collapsed = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($collapsed !== []) {
                    array_pop($collapsed);
                }

                continue;
            }

            $collapsed[] = $segment;
        }

        return $collapsed;
    }

    private static function normalizeAbsolutePath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || PathHelper::hasScheme($path)) {
            return null;
        }

        return PHP_OS_FAMILY === 'Windows'
            ? self::normalizeWindowsAbsolutePath($path)
            : self::normalizeUnixAbsolutePath($path);
    }

    private static function normalizeUnixAbsolutePath(string $path): ?string
    {
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if (!is_string($cwd) || $cwd === '') {
                return null;
            }

            $path = rtrim($cwd, '/') . '/' . $path;
        }

        $segments = self::collapseSegments(explode('/', $path));

        return '/' . implode('/', $segments);
    }

    private static function normalizeWindowsAbsolutePath(string $path): ?string
    {
        $path = str_replace('/', '\\', $path);
        if (preg_match('/^[A-Za-z]:[^\\\\]/', $path) === 1) {
            return null;
        }

        if (preg_match('/^\\\\\\\\([^\\\\]+)\\\\([^\\\\]+)(?:\\\\(.*))?$/s', $path, $matches) === 1) {
            $prefix = '\\\\' . $matches[1] . '\\' . $matches[2];
            $tail = $matches[3] ?? '';

            return self::buildWindowsPath($prefix, $tail);
        }

        if (preg_match('/^([A-Za-z]):\\\\(.*)$/s', $path, $matches) === 1) {
            return self::buildWindowsPath(strtoupper($matches[1]) . ':', $matches[2]);
        }

        if (str_starts_with($path, '\\')) {
            return null;
        }

        $cwd = getcwd();
        if (!is_string($cwd) || $cwd === '') {
            return null;
        }

        return self::normalizeWindowsAbsolutePath(rtrim($cwd, '\\') . '\\' . $path);
    }

    private static function buildWindowsPath(string $prefix, string $tail): string
    {
        $segments = self::collapseSegments(explode('\\', $tail));

        return $prefix . '\\' . implode('\\', $segments);
    }
}
