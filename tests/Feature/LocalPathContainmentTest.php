<?php

declare(strict_types=1);

use Infocyph\Pathwise\Security\LocalPathContainment;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

function containmentTempDirectory(string $prefix): string
{
    $directory = PathHelper::join(sys_get_temp_dir(), $prefix . bin2hex(random_bytes(8)));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create containment test directory '{$directory}'.");
    }

    return $directory;
}

beforeEach(function (): void {
    $this->containmentRoot = containmentTempDirectory('pathwise_containment_');
});

afterEach(function (): void {
    if (is_dir($this->containmentRoot) && !is_link($this->containmentRoot)) {
        FlysystemHelper::deleteDirectory($this->containmentRoot);
    }
});

test('canonical containment accepts root and future descendants', function (): void {
    $future = PathHelper::join($this->containmentRoot, 'future', 'file.txt');

    expect(LocalPathContainment::isSameOrDescendant($this->containmentRoot, $this->containmentRoot))->toBeTrue()
        ->and(LocalPathContainment::isSameOrDescendant($this->containmentRoot, $future))->toBeTrue();
});

test('canonical containment rejects sibling prefix and traversal escapes', function (): void {
    $sibling = $this->containmentRoot . '-sibling';
    mkdir($sibling, 0700, true);

    try {
        expect(LocalPathContainment::isSameOrDescendant($this->containmentRoot, $sibling))->toBeFalse()
            ->and(LocalPathContainment::isSameOrDescendant(
                $this->containmentRoot,
                PathHelper::join($this->containmentRoot, '..', basename($sibling)),
            ))->toBeFalse();
    } finally {
        if (is_dir($sibling)) {
            FlysystemHelper::deleteDirectory($sibling);
        }
    }
});

test('canonical containment rejects schemes and null bytes', function (): void {
    expect(LocalPathContainment::isSameOrDescendant($this->containmentRoot, 'memory://file.txt'))->toBeFalse()
        ->and(LocalPathContainment::isSameOrDescendant($this->containmentRoot, "bad\0path"))->toBeFalse();
});

test('canonical containment resolves symlink before lexical parent traversal', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $outside = containmentTempDirectory('pathwise_containment_outside_');
    $link = PathHelper::join($this->containmentRoot, 'linked');
    symlink($outside, $link);

    try {
        $candidate = PathHelper::join($link, '..', basename($this->containmentRoot), 'future.txt');

        expect(LocalPathContainment::isSameOrDescendant($this->containmentRoot, $candidate))->toBeFalse();
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        if (is_dir($outside)) {
            FlysystemHelper::deleteDirectory($outside);
        }
    }
});

test('canonical containment rejects existing symlink parent escape for future target', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $outside = containmentTempDirectory('pathwise_containment_outside_');
    $link = PathHelper::join($this->containmentRoot, 'linked');
    symlink($outside, $link);

    try {
        expect(LocalPathContainment::isSameOrDescendant(
            $this->containmentRoot,
            PathHelper::join($link, 'future', 'file.txt'),
        ))->toBeFalse();
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        if (is_dir($outside)) {
            FlysystemHelper::deleteDirectory($outside);
        }
    }
});

test('canonical containment rejects broken symlink paths', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $link = PathHelper::join($this->containmentRoot, 'broken');
    symlink(PathHelper::join($this->containmentRoot, 'missing-target'), $link);

    try {
        expect(LocalPathContainment::isSameOrDescendant($this->containmentRoot, $link))->toBeFalse();
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
    }
});

test('windows containment enforces drive rooted and case rules', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(PHP_OS_FAMILY)->not->toBe('Windows');

        return;
    }

    $root = realpath($this->containmentRoot);
    expect($root)->toBeString();

    $root = (string) $root;
    $drive = substr($root, 0, 2);
    $caseVariant = swapcase($root);

    expect(LocalPathContainment::isSameOrDescendant($root, $caseVariant))->toBeTrue()
        ->and(LocalPathContainment::isSameOrDescendant($root, $drive . 'relative\\file.txt'))->toBeFalse()
        ->and(LocalPathContainment::isSameOrDescendant($root, '\\rooted-without-drive\\file.txt'))->toBeFalse()
        ->and(LocalPathContainment::isSameOrDescendant($root, '\\\\server\\share\\file.txt'))->toBeFalse();
});

test('windows containment rejects a link or reparse escape when link creation is available', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(PHP_OS_FAMILY)->not->toBe('Windows');

        return;
    }

    $outside = containmentTempDirectory('pathwise_containment_windows_outside_');
    $link = PathHelper::join($this->containmentRoot, 'linked');
    set_error_handler(static fn(): bool => true);
    try {
        $linked = symlink($outside, $link, true);
    } finally {
        restore_error_handler();
    }

    try {
        if (!$linked) {
            expect(is_link($link))->toBeFalse();

            return;
        }

        expect(LocalPathContainment::isSameOrDescendant(
            $this->containmentRoot,
            PathHelper::join($link, 'future', 'file.txt'),
        ))->toBeFalse();
    } finally {
        if (is_link($link) || file_exists($link)) {
            unlink($link);
        }
        if (is_dir($outside)) {
            FlysystemHelper::deleteDirectory($outside);
        }
    }
});

function swapcase(string $value): string
{
    $result = '';
    foreach (str_split($value) as $character) {
        $result .= ctype_upper($character) ? strtolower($character) : strtoupper($character);
    }

    return $result;
}
