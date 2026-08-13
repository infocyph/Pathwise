<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\Exceptions\MissingExtensionException;
use Infocyph\Pathwise\Exceptions\UnsupportedStorageOperationException;

class PermissionsHelper
{
    private const array PERMISSION_TRIPLETS = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx'];

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
        self::assertDirectLocalPath($path);

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
        self::assertDirectLocalPath($path);

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
        self::assertDirectLocalPath($path);

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
        $owner = self::PERMISSION_TRIPLETS[($permissions >> 6) & 7];
        $group = self::PERMISSION_TRIPLETS[($permissions >> 3) & 7];
        $other = self::PERMISSION_TRIPLETS[$permissions & 7];

        if (($permissions & 0x0800) !== 0) {
            $owner[2] = $owner[2] === 'x' ? 's' : 'S';
        }
        if (($permissions & 0x0400) !== 0) {
            $group[2] = $group[2] === 'x' ? 's' : 'S';
        }
        if (($permissions & 0x0200) !== 0) {
            $other[2] = $other[2] === 'x' ? 't' : 'T';
        }

        return $owner . $group . $other;
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
        self::assertDirectLocalPath($path);
        if (!file_exists($path)) {
            return null;
        }

        $permissions = fileperms($path);
        if (!is_int($permissions)) {
            return null;
        }

        return self::formatPermissions($permissions);
    }

    /**
     * Retrieves the owner and group of the given path.
     *
     * Returns resolved owner and group names for an existing local path.
     * Missing paths produce null; unavailable POSIX support throws explicitly.
     *
     * @param string $path The path to the file or directory to retrieve
     *                     ownership for.
     * @return array{owner: string|null, group: string|null}|null An array containing the owner and group of the file
     *                                                            or directory, or null if the file or directory does not exist, or
     *                                                            if ownership functions are not supported on the current system.
     */
    public static function getOwnership(string $path): ?array
    {
        self::assertDirectLocalPath($path);
        if (!self::isPosixSupported()) {
            throw new MissingExtensionException('Ownership operations require ext-posix.');
        }

        if (!file_exists($path)) {
            return null;
        }

        $ownerId = fileowner($path);
        $groupId = filegroup($path);

        $owner = null;
        if (is_int($ownerId)) {
            $ownerInfo = posix_getpwuid($ownerId);
            if (is_array($ownerInfo)) {
                $owner = $ownerInfo['name'];
            }
        }

        $group = null;
        if (is_int($groupId)) {
            $groupInfo = posix_getgrgid($groupId);
            if (is_array($groupInfo)) {
                $group = $groupInfo['name'];
            }
        }

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
     *                     path does not exist.
     */
    public static function getPermissions(string $path): ?string
    {
        self::assertDirectLocalPath($path);
        if (!file_exists($path)) {
            return null;
        }

        $permissions = fileperms($path);
        if (!is_int($permissions)) {
            return null;
        }

        return substr(sprintf('%04o', $permissions), -4);
    }

    /**
     * Determines if the given path is owned by the current user.
     *
     * @param string $path The path to the file or directory to check ownership on.
     * @return bool True if the path is owned by the current user, false otherwise.
     */
    public static function isOwnedByCurrentUser(string $path): bool
    {
        self::assertDirectLocalPath($path);

        return self::isPosixSupported() && fileowner($path) === posix_geteuid();
    }

    /**
     * Sets the ownership of the file or directory at the given path.
     *
     * @param string $path The path to the file or directory to set ownership on.
     * @param string $owner The username of the new owner.
     * @param string|null $group The groupname of the new group, or null to leave the group unchanged.
     * @throws FileAccessException If the ownership operation fails.
     * @throws MissingExtensionException If ownership functions are unavailable.
     */
    public static function setOwnership(string $path, string $owner, ?string $group = null): self
    {
        self::assertDirectLocalPath($path);
        if (!self::isPosixSupported()) {
            throw new MissingExtensionException('Ownership operations require ext-posix.');
        }

        $result = chown($path, $owner);
        if ($group !== null && $group !== '') {
            $result = $result && chgrp($path, $group);
        }

        if (!$result) {
            throw new FileAccessException("Failed to set ownership on {$path}");
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
     * @throws FileAccessException If the operation fails.
     */
    public static function setPermissions(string $path, int $permissions): self
    {
        self::assertDirectLocalPath($path);
        if (!chmod($path, $permissions)) {
            throw new FileAccessException("Failed to set permissions on {$path}");
        }

        return new self();
    }

    private static function assertDirectLocalPath(string $path): void
    {
        if (!FlysystemHelper::isLocalPath($path)) {
            throw new UnsupportedStorageOperationException(
                "Permission operations require a direct-local path: {$path}",
            );
        }
    }

    private static function isPosixSupported(): bool
    {
        return function_exists('posix_getpwuid') && function_exists('posix_getgrgid');
    }
}
