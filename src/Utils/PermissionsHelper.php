<?php

namespace Infocyph\Pathwise\Utils;

use RuntimeException;

class PermissionsHelper
{
    private static array $permissionCache = [];

    /**
     * Checks if the specified path is executable.
     *
     * This method determines whether the file or directory at the given
     * path has executable permissions for the current user.
     *
     * @param string $path The path to the file or directory to check.
     * @return bool True if the path is executable, false otherwise.
     */
    public static function canExecute(string $path): bool
    {
        return is_executable($path);
    }


    /**
     * Checks if the specified path is readable.
     *
     * This method determines whether the file or directory at the given
     * path can be read from by the current user. It returns true if the
     * path is readable and false otherwise.
     *
     * @param string $path The path to the file or directory to check.
     * @return bool True if the path is readable, false otherwise.
     */
    public static function canRead(string $path): bool
    {
        return is_readable($path);
    }

    /**
     * Checks if the specified path is writable.
     *
     * This method determines whether the file or directory at the given
     * path can be written to by the current user. It returns true if the
     * path is writable and false otherwise.
     *
     * @param string $path The path to check for writability.
     * @return bool True if the path is writable, false otherwise.
     */
    public static function canWrite(string $path): bool
    {
        return is_writable($path);
    }

    /**
     * Formats an integer representing file permissions into a human-readable string.
     *
     * This method converts a numerical representation of file permissions
     * into the traditional 'rwx' format, indicating read, write, and execute
     * permissions for the owner, group, and others. It also handles special
     * permissions like setuid, setgid, and sticky bit by converting them to
     * 's' or 't' where applicable.
     *
     * @param int $permissions The numeric representation of the file permissions.
     * @return string The formatted string representing the permissions.
     */
    public static function formatPermissions(int $permissions): string
    {
        $flags = [
            // Owner permissions
            0x0100 => 'r',
            0x0080 => 'w',
            0x0040 => ($permissions & 0x0800) ? 's' : 'x',
            // Group permissions
            0x0020 => 'r',
            0x0010 => 'w',
            0x0008 => ($permissions & 0x0400) ? 's' : 'x',
            // Others permissions
            0x0004 => 'r',
            0x0002 => 'w',
            0x0001 => ($permissions & 0x0200) ? 't' : 'x',
        ];

        return array_reduce(array_keys($flags), fn($info, $flag) => $info . (($permissions & $flag) ? $flags[$flag] : '-'), '');
    }


    /**
     * Converts the permissions of a file or directory into a human-readable string.
     *
     * This method takes a file or directory path and returns a string
     * representation of its permissions in the traditional 'rwx' format.
     * The result is a string like 'rwxr-x--' or 'rw-r--r--', indicating
     * read, write, and execute permissions for the owner, group, and others.
     *
     * If the file or directory does not exist, this method returns null.
     *
     * @param string $path The path to the file or directory to format permissions for.
     * @return string|null The human-readable permissions string, or null if the path does not exist.
     */
    public static function getHumanReadablePermissions(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        return self::formatPermissions(fileperms($path));
    }

    /**
     * Retrieves the owner and group of the given path.
     *
     * Returns an array with keys 'owner' and 'group', each containing the
     * username or groupname of the owner or group of the file or directory,
     * respectively. If the file or directory does not exist, or if ownership
     * functions are not supported on the current system, this method returns
     * null.
     *
     * @param string $path The path to the file or directory to retrieve
     *     ownership for.
     * @return array|null An array containing the owner and group of the file
     *     or directory, or null if the file or directory does not exist, or
     *     if ownership functions are not supported on the current system.
     */
    public static function getOwnership(string $path): ?array
    {
        if (!self::isPosixSupported()) {
            throw new RuntimeException("Ownership functions are only supported on Unix-based systems.");
        }

        if (!file_exists($path)) {
            return null;
        }

        $owner = posix_getpwuid(fileowner($path))['name'] ?? null;
        $group = posix_getgrgid(filegroup($path))['name'] ?? null;
        return compact('owner', 'group');
    }

    /**
     * Retrieves the permissions of the specified file or directory.
     *
     * This method returns the file or directory permissions as a string
     * representation of an octal number, e.g., '0755'. If the file or
     * directory does not exist, it returns null.
     *
     * @param string $path The path to the file or directory.
     * @return string|null The permissions as an octal string, or null if the
     *         path does not exist.
     */
    public static function getPermissions(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        return self::$permissionCache[$path]['permissions'] ??= substr(sprintf('%04o', fileperms($path)), -4);
    }

    /**
     * Determines if the given path is owned by the current user.
     *
     * @param string $path The path to the file or directory to check ownership on.
     * @return bool True if the path is owned by the current user, false otherwise.
     */
    public static function isOwnedByCurrentUser(string $path): bool
    {
        return self::isPosixSupported() && fileowner($path) === posix_geteuid();
    }

    /**
     * Sets the ownership of the file or directory at the given path.
     *
     * @param string $path The path to the file or directory to set ownership on.
     * @param string $owner The username of the new owner.
     * @param string|null $group The groupname of the new group, or null to leave the group unchanged.
     * @return $this
     * @throws RuntimeException If the operation fails or if ownership functions are not supported on the current system.
     */
    public static function setOwnership(string $path, string $owner, ?string $group = null): self
    {
        if (!self::isPosixSupported()) {
            throw new RuntimeException("Ownership functions are only supported on Unix-based systems.");
        }

        $result = chown($path, $owner);
        if ($group) {
            $result = $result && chgrp($path, $group);
        }

        if (!$result) {
            throw new RuntimeException("Failed to set ownership on {$path}");
        }

        return new self();
    }


    /**
     * Sets the permissions of the file or directory at the given path.
     *
     * This method changes the permissions of the file or directory at the
     * given path to the given value. The value should be an octal number,
     * e.g. 0755.
     *
     * @param string $path The path to the file or directory to set permissions on.
     * @param int $permissions The new permissions for the file or directory.
     * @return $this
     * @throws RuntimeException If the operation fails.
     */
    public static function setPermissions(string $path, int $permissions): self
    {
        if (!chmod($path, $permissions)) {
            throw new RuntimeException("Failed to set permissions on {$path}");
        }
        return new self();
    }

    /**
     * Determines if the current system supports POSIX-style ownership
     * functions.
     *
     * This method checks if the 'posix_getpwuid' and 'posix_getgrgid' functions
     * are available. If they are, it returns true, indicating that
     * POSIX-style ownership functions are supported on the current system. If
     * they are not available, it returns false.
     *
     * @return bool True if POSIX-style ownership functions are supported,
     *     false otherwise.
     */
    private static function isPosixSupported(): bool
    {
        return function_exists('posix_getpwuid') && function_exists('posix_getgrgid');
    }
}
