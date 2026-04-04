<?php

namespace Infocyph\Pathwise\Utils;

class PathHelper
{
    private static array $cache = [];

    /**
     * Changes the extension of a file path.
     *
     * @param string $path The file path to change the extension of.
     * @param string $newExtension The new extension to use.
     * @return string The modified file path with the new extension.
     *
     * @example
     * PathHelper::changeExtension('path/to/file.txt', 'docx');
     * // returns 'path/to/file.docx'
     */
    public static function changeExtension(string $path, string $newExtension): string
    {
        return self::normalize(
            pathinfo($path, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.' . ltrim(
                $newExtension,
                '.',
            ),
        );
    }

    /**
     * Creates a directory with the given path and permissions.
     *
     * If the directory already exists, this function simply returns true.
     * If the directory does not exist, this function attempts to create it,
     * and returns true if successful, or false if not.
     *
     * @param string $path The path to the directory to create.
     * @param int $permissions The permissions to set for the directory.
     * @return bool True if the directory was successfully created, false otherwise.
     */
    public static function createDirectory(string $path, int $permissions = 0755): bool
    {
        $isLocalDirectory = !self::hasScheme($path) && is_dir($path);
        if (FlysystemHelper::directoryExists($path) || $isLocalDirectory) {
            return true;
        }

        FlysystemHelper::createDirectory($path);
        if (!self::hasScheme($path)) {
            @chmod($path, $permissions);
        }

        return true;
    }

    /**
     * Creates a temporary directory with a unique name in the system's temporary directory.
     *
     * @param string $prefix The prefix for the temporary directory's name.
     * @return string|false The path to the created temporary directory, or false on failure.
     */
    public static function createTempDirectory(string $prefix = 'temp_'): string|false
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        return mkdir($tempDir) ? $tempDir : false;
    }

    /**
     * Creates a temporary file with a unique name in the system's temporary directory.
     *
     * @param string $prefix The prefix for the temporary file's name.
     * @return string|false The path to the created temporary file, or false on failure.
     */
    public static function createTempFile(string $prefix = 'temp_'): string|false
    {
        return tempnam(sys_get_temp_dir(), $prefix);
    }

    /**
     * Recursively deletes a directory and all its contents.
     *
     * @param string $directory The path to the directory to delete.
     * @return bool True if the directory was successfully deleted, false otherwise.
     */
    public static function deleteDirectory(string $directory): bool
    {
        $isLocalDirectory = !self::hasScheme($directory) && is_dir($directory);
        if (!$isLocalDirectory && !FlysystemHelper::directoryExists($directory)) {
            return false;
        }

        FlysystemHelper::deleteDirectory($directory);

        return !FlysystemHelper::directoryExists($directory);
    }

    /**
     * Deletes a file.
     *
     * @param string $file The path to the file to delete.
     * @return bool True if the file was successfully deleted, false otherwise.
     */
    public static function deleteFile(string $file): bool
    {
        $isLocalFile = !self::hasScheme($file) && is_file($file);
        if (!$isLocalFile && !FlysystemHelper::fileExists($file)) {
            return false;
        }

        FlysystemHelper::delete($file);

        return !FlysystemHelper::fileExists($file);
    }

    /**
     * Returns the file extension of the given path.
     *
     * @param string $path The path to get the extension of.
     * @return string The file extension of the given path.
     */
    public static function getExtension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Returns the filename of the given path, optionally without extension.
     *
     * @param string $path The path to get the filename of.
     * @param bool $withExtension Whether to include the file extension in the result.
     * @return string The filename of the given path, optionally without extension.
     */
    public static function getFilename(string $path, bool $withExtension = true): string
    {
        return $withExtension ? basename($path) : pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Determines the type of the specified path.
     *
     * This function checks if the provided path is a file, directory, or symbolic link.
     *
     * @param string $path The path to check.
     * @return string|null Returns 'file' if the path is a file, 'directory' if it's a directory,
     * 'link' if it's a symbolic link, or null if none of these.
     */
    public static function getPathType(string $path): ?string
    {
        if (FlysystemHelper::fileExists($path)) {
            return 'file';
        }

        if (FlysystemHelper::directoryExists($path)) {
            return 'directory';
        }

        return is_link($path) ? 'link' : null;
    }

    public static function hasScheme(string $path): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]+:\/\//', $path) === 1;
    }

    /**
     * Checks if a given path is an absolute path.
     *
     * This method determines if the path is absolute by checking if it starts
     * with the directory separator on Unix-based systems or matches a drive letter
     * on Windows-based systems.
     *
     * @param string $path The path to check.
     * @return bool True if the path is absolute, false otherwise.
     */
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (self::hasScheme($path)) {
            return true;
        }

        if (DIRECTORY_SEPARATOR === '/') {
            return str_starts_with($path, '/');
        }

        return preg_match('/^(?:[a-zA-Z]:[\\\\\\/]|\\\\\\\\)/', $path) === 1;
    }

    /**
     * Determines if the given path is valid.
     *
     * The given path is considered invalid if it contains any of the following characters:
     * - Less than (`<`)
     * - Greater than (`>`)
     * - Double quote (`"`)
     * - Pipe (`|`)
     * - Question mark (`?`)
     * - Asterisk (`*`)
     *
     * @param string $path The path to check.
     * @return bool True if the path is valid, false otherwise.
     */
    public static function isValidPath(string $path): bool
    {
        return !preg_match('/[<>:"|?*]/', $path);
    }

    /**
     * Joins multiple path segments into a single path.
     *
     * This method takes an arbitrary number of path segments and returns a
     * single path that represents the concatenation of all of the segments.
     * Any redundant separators are collapsed, and any '.' or '..' segments
     * are eliminated.
     *
     * @param string ...$segments The segments to join.
     * @return string The joined path.
     */
    public static function join(string ...$segments): string
    {
        if ($segments === []) {
            return '';
        }

        $path = array_shift($segments) ?? '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (self::hasScheme($path)) {
                if (preg_match('/^([a-zA-Z0-9._-]+):\/\/(.*)$/', $path, $matches) === 1) {
                    $scheme = strtolower($matches[1]);
                    $base = trim(str_replace('\\', '/', $matches[2]), '/');
                    $next = trim(str_replace('\\', '/', $segment), '/');

                    if ($next === '') {
                        $path = $scheme . '://' . $base;
                        continue;
                    }

                    $base = $base === '' ? $next : $base . '/' . $next;
                    $path = $scheme . '://' . $base;
                    continue;
                }

                $path = rtrim(str_replace('\\', '/', $path), '/') . '/' . ltrim(str_replace('\\', '/', $segment), '/');
                continue;
            }

            $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . ltrim($segment, '/\\');
        }

        return self::normalize($path);
    }

    /**
     * Normalize a path, collapsing any redundant separators and eliminating
     * any '.' or '..' segments.
     *
     * The normalized path is cached for subsequent calls.
     *
     * @param string $path The path to normalize.
     * @return string The normalized path.
     */
    public static function normalize(string $path): string
    {
        $originalPath = $path;
        if (isset(self::$cache[$originalPath])) {
            return self::$cache[$originalPath];
        }

        if ($path === '') {
            return self::$cache[$originalPath] = '';
        }

        if (self::hasScheme($path)) {
            return self::$cache[$originalPath] = self::normalizeSchemePath($path);
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $prefix = '';
        if (preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1) {
            $prefix = strtoupper(substr($path, 0, 2)) . DIRECTORY_SEPARATOR;
            $path = substr($path, 3);
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = ltrim($path, DIRECTORY_SEPARATOR);
        }

        $parts = preg_split('/[\\\\\\/]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($stack !== [] && end($stack) !== '..') {
                    array_pop($stack);
                } elseif ($prefix === '') {
                    $stack[] = '..';
                }
            } else {
                $stack[] = $part;
            }
        }

        $normalized = $prefix . implode(DIRECTORY_SEPARATOR, $stack);
        if ($normalized === '' && $prefix !== '') {
            $normalized = $prefix;
        }

        return self::$cache[$originalPath] = $normalized;
    }

    /**
     * Determines if the specified path exists.
     *
     * @param string $path The path to check.
     *
     * @return bool True if the path exists, false otherwise.
     */
    public static function pathExists(string $path): bool
    {
        if (file_exists($path)) {
            return true;
        }

        try {
            return FlysystemHelper::has($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the relative path from the given from path to the given to path.
     *
     * @param string $from The path to get the relative path from.
     * @param string $to The path to get the relative path to.
     * @return string The relative path from the given from path to the given to path.
     *
     * @example
     * PathHelper::relativePath('/var/www/html', '/var/www/html/js/app.js');
     * // returns 'js/app.js'
     *
     * @example
     * PathHelper::relativePath('/var/www/html', '/var/www/assets/css/style.css');
     * // returns '../assets/css/style.css'
     */
    public static function relativePath(string $from, string $to): string
    {
        $from = explode(DIRECTORY_SEPARATOR, self::normalize($from));
        $to = explode(DIRECTORY_SEPARATOR, self::normalize($to));

        while (count($from) && count($to) && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        return str_repeat('..' . DIRECTORY_SEPARATOR, count($from)) . implode(DIRECTORY_SEPARATOR, $to);
    }

    /**
     * Sanitizes a given path by removing all non-alphanumeric characters except for dash, underscore, slash, and dot.
     *
     * @param string $path The path to sanitize.
     * @return string The sanitized path.
     */
    public static function sanitize(string $path): string
    {
        return preg_replace('/[^A-Za-z0-9\-_\/\.]/', '', $path) ?? '';
    }

    /**
     * Converts a relative path to an absolute path.
     *
     * If the given path is already absolute, it is returned unchanged.
     * Otherwise, the given path is resolved relative to the specified base path.
     *
     * @param string $path The path to convert.
     * @param string|null $base The base path to resolve against if the given path is relative.
     * @return string The absolute path.
     */
    public static function toAbsolutePath(string $path, ?string $base = null): string
    {
        if (self::isAbsolute($path)) {
            return self::normalize($path);
        }
        $base ??= getcwd() ?: '.';
        return self::normalize(self::join($base, $path));
    }

    private static function normalizeSchemePath(string $path): string
    {
        if (preg_match('/^([a-zA-Z0-9._-]+):\/\/(.*)$/', $path, $matches) !== 1) {
            return $path;
        }

        $scheme = strtolower($matches[1]);
        $location = str_replace('\\', '/', $matches[2]);
        $leadingSlash = str_starts_with($location, '/');

        $parts = preg_split('/\/+/', trim($location, '/'), -1, PREG_SPLIT_NO_EMPTY);
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }

            if ($part === '..') {
                if ($stack !== []) {
                    array_pop($stack);
                }

                continue;
            }

            $stack[] = $part;
        }

        $normalized = implode('/', $stack);
        if ($leadingSlash && $normalized !== '') {
            $normalized = '/' . $normalized;
        }

        return $scheme . '://' . $normalized;
    }
}
