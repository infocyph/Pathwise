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

    private static function absolutePath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || PathHelper::hasScheme($path)) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return self::windowsAbsolutePath($path);
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();
        if (!is_string($cwd)) {
            return null;
        }

        return rtrim($cwd, '/') . '/' . $path;
    }

    /** @param list<string> $suffix */
    private static function appendSuffix(string $base, array $suffix): ?string
    {
        foreach ($suffix as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }

            $base .= DIRECTORY_SEPARATOR . $segment;
        }

        return $base;
    }

    private static function canonicalPath(string $path): ?string
    {
        $absolute = self::absolutePath($path);
        if ($absolute === null) {
            return null;
        }

        /** @var list<string> $suffix */
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
        if (!is_string($resolved)) {
            return null;
        }

        return self::appendSuffix($resolved, $suffix);
    }

    private static function comparisonKey(string $path): string
    {
        $key = rtrim(str_replace('\\', '/', $path), '/');
        if ($key === '') {
            $key = '/';
        }

        return PHP_OS_FAMILY === 'Windows' ? strtolower($key) : $key;
    }

    private static function windowsAbsolutePath(string $path): ?string
    {
        $path = str_replace('/', '\\', $path);
        if (preg_match('/^[A-Za-z]:[^\\\\]/', $path) === 1) {
            return null;
        }

        if (preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+(?:\\\\.*)?$/s', $path) === 1) {
            return $path;
        }

        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return strtoupper($path[0]) . substr($path, 1);
        }

        if (str_starts_with($path, '\\')) {
            return null;
        }

        $cwd = getcwd();
        if (!is_string($cwd)) {
            return null;
        }

        return rtrim(str_replace('/', '\\', $cwd), '\\') . '\\' . $path;
    }
}
