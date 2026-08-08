<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\MissingExtensionException;
use Infocyph\Pathwise\Utils\PermissionsHelper;

beforeEach(function () {
    $this->tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_file_', true) . '.txt';
    file_put_contents($this->tempFilePath, 'Test content');
});
afterEach(function () {
    if (file_exists($this->tempFilePath)) {
        unlink($this->tempFilePath);
    }
});

// Test getPermissions
test('it retrieves file permissions', function () {
    $permissions = PermissionsHelper::getPermissions($this->tempFilePath);
    expect($permissions)->toBeString();
});

// Test setPermissions
test('it sets file permissions', function () {
    $result = PermissionsHelper::setPermissions($this->tempFilePath, 0740);

    expect($result)->toBeInstanceOf(PermissionsHelper::class)
        ->and(PermissionsHelper::getPermissions($this->tempFilePath))->toBeString();

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(PermissionsHelper::getPermissions($this->tempFilePath))->toBe('0740');
    }
});

// Test canRead
test('it checks if file is readable', function () {
    expect(PermissionsHelper::canRead($this->tempFilePath))->toBeTrue();
});

// Test canWrite
test('it checks if file is writable', function () {
    expect(PermissionsHelper::canWrite($this->tempFilePath))->toBeTrue();
});

// Test canExecute
test('it checks if file is executable', function () {
    expect(PermissionsHelper::canExecute($this->tempFilePath))->toBeFalse();
});

// Test getOwnership (POSIX only)
test('it retrieves file ownership', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(fn() => PermissionsHelper::getOwnership($this->tempFilePath))
            ->toThrow(MissingExtensionException::class, 'ext-posix');

        return;
    }

    $ownership = PermissionsHelper::getOwnership($this->tempFilePath);
    expect($ownership)->toHaveKeys(['owner', 'group']);
});

// Test setOwnership (POSIX only)
test('it sets file ownership', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(fn() => PermissionsHelper::setOwnership($this->tempFilePath, 'owner'))
            ->toThrow(MissingExtensionException::class, 'ext-posix');

        return;
    }

    $originalOwner = posix_getpwuid(fileowner($this->tempFilePath))['name'] ?? null;
    PermissionsHelper::setOwnership($this->tempFilePath, $originalOwner);
    $ownership = PermissionsHelper::getOwnership($this->tempFilePath);
    expect($ownership['owner'])->toBe($originalOwner);
});

// Test isOwnedByCurrentUser (POSIX only)
test('it checks if file is owned by current user', function () {
    expect(PermissionsHelper::isOwnedByCurrentUser($this->tempFilePath))
        ->toBe(PHP_OS_FAMILY !== 'Windows');
});

// Test getHumanReadablePermissions
test('it retrieves human-readable file permissions', function () {
    $permissions = PermissionsHelper::getHumanReadablePermissions($this->tempFilePath);
    expect($permissions)->toBeString()->toMatch('/^[r-][w-][x-][r-][w-][x-][r-][w-][x-]$/');
});

// Test formatPermissions
test('it formats permissions as human-readable string', function () {
    $permissions = PermissionsHelper::formatPermissions(0755);
    expect($permissions)->toBe('rwxr-xr-x');
});

test('it formats special permission bits without inventing execute access', function () {
    expect(PermissionsHelper::formatPermissions(0644 | 04000))->toBe('rwSr--r--')
        ->and(PermissionsHelper::formatPermissions(0644 | 02000))->toBe('rw-r-Sr--')
        ->and(PermissionsHelper::formatPermissions(0644 | 01000))->toBe('rw-r--r-T');
});

test('it observes permission changes made outside the helper', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PermissionsHelper::setPermissions($this->tempFilePath, 0600))
            ->toBeInstanceOf(PermissionsHelper::class)
            ->and(PermissionsHelper::getPermissions($this->tempFilePath))->toBeString();

        return;
    }

    chmod($this->tempFilePath, 0644);
    expect(PermissionsHelper::getPermissions($this->tempFilePath))->toBe('0644');

    chmod($this->tempFilePath, 0600);
    expect(PermissionsHelper::getPermissions($this->tempFilePath))->toBe('0600');
});
