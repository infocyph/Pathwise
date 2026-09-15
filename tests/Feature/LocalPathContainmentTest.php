<?php

declare(strict_types=1);

use Infocyph\Pathwise\Security\LocalPathContainment;

beforeEach(function (): void {
    $this->containmentRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_containment_' . bin2hex(random_bytes(8));
    $this->allowedRoot = $this->containmentRoot . DIRECTORY_SEPARATOR . 'allowed';
    $this->siblingRoot = $this->containmentRoot . DIRECTORY_SEPARATOR . 'allowed-sibling';
    $this->outsideRoot = $this->containmentRoot . DIRECTORY_SEPARATOR . 'outside';

    foreach ([$this->allowedRoot, $this->siblingRoot, $this->outsideRoot] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create containment fixture: {$directory}");
        }
    }
});

afterEach(function (): void {
    if (!is_dir($this->containmentRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->containmentRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($this->containmentRoot);
});

test('canonical containment accepts the root and descendants including missing targets', function (): void {
    $existing = $this->allowedRoot . DIRECTORY_SEPARATOR . 'existing';
    mkdir($existing, 0700);

    expect(LocalPathContainment::isSameOrDescendant($this->allowedRoot, $this->allowedRoot))->toBeTrue()
        ->and(LocalPathContainment::isSameOrDescendant(
            $this->allowedRoot,
            $existing . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'future.txt',
        ))->toBeTrue();
});

test('canonical containment rejects sibling prefixes and traversal outside the root', function (): void {
    expect(LocalPathContainment::isSameOrDescendant(
        $this->allowedRoot,
        $this->siblingRoot . DIRECTORY_SEPARATOR . 'file.txt',
    ))->toBeFalse()
        ->and(LocalPathContainment::isSameOrDescendant(
            $this->allowedRoot,
            $this->allowedRoot . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'outside' . DIRECTORY_SEPARATOR . 'file.txt',
        ))->toBeFalse();
});

test('canonical containment rejects storage schemes and null bytes', function (): void {
    expect(LocalPathContainment::isSameOrDescendant($this->allowedRoot, 'assets://file.txt'))->toBeFalse()
        ->and(LocalPathContainment::isSameOrDescendant($this->allowedRoot, "file\0.txt"))->toBeFalse();
});

test('canonical containment resolves an existing symlink parent before checking a future target', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $link = $this->allowedRoot . DIRECTORY_SEPARATOR . 'escape';
    symlink($this->outsideRoot, $link);

    expect(LocalPathContainment::isSameOrDescendant(
        $this->allowedRoot,
        $link . DIRECTORY_SEPARATOR . 'future.txt',
    ))->toBeFalse();
});

test('canonical containment fails closed for a broken symbolic link', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $link = $this->allowedRoot . DIRECTORY_SEPARATOR . 'broken';
    symlink($this->outsideRoot . DIRECTORY_SEPARATOR . 'missing', $link);

    expect(LocalPathContainment::isSameOrDescendant($this->allowedRoot, $link))->toBeFalse();
});
