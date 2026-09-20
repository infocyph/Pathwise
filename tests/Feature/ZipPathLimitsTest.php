<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use Infocyph\Pathwise\Security\ZipEntryValidator;

test('zip entry validation enforces segment byte length', function (): void {
    expect(fn() => ZipEntryValidator::validate(str_repeat('a', 256) . '.txt', sys_get_temp_dir()))
        ->toThrow(UnsafeArchiveEntryException::class);
});

test('zip entry validation enforces total path byte length', function (): void {
    expect(fn() => ZipEntryValidator::validate(
        'alpha/beta/file.txt',
        sys_get_temp_dir(),
        255,
        10,
        64,
    ))->toThrow(UnsafeArchiveEntryException::class);
});

test('zip entry validation enforces path depth', function (): void {
    expect(fn() => ZipEntryValidator::validate(
        'one/two/three/file.txt',
        sys_get_temp_dir(),
        255,
        4096,
        3,
    ))->toThrow(UnsafeArchiveEntryException::class);
});

test('zip path limits reject negative configuration', function (): void {
    expect(fn() => ZipEntryValidator::validateArchiveLimits(10, 100, 1000, 10.0, -1))
        ->toThrow(InvalidArgumentException::class);
});
