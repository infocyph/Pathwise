<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\FileManager\SafeSymlinkManager;
use Infocyph\Pathwise\Utils\PathHelper;

function removeTestSymlink(string $link): bool
{
    if (!is_link($link)) {
        return false;
    }

    set_error_handler(static fn(): bool => true);

    try {
        if (unlink($link)) {
            return true;
        }

        return rmdir($link);
    } finally {
        restore_error_handler();
    }
}

function symlinkTestDirectory(string $prefix): string
{
    $directory = PathHelper::join(sys_get_temp_dir(), $prefix . bin2hex(random_bytes(8)));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory '{$directory}'.");
    }

    return $directory;
}

function supportsSymlinkCreation(string $directory): bool
{
    $target = PathHelper::join($directory, 'probe-target');
    $link = PathHelper::join($directory, 'probe-link');
    mkdir($target, 0700);

    set_error_handler(static fn(): bool => true);
    try {
        $created = symlink($target, $link);
    } finally {
        restore_error_handler();
    }

    if ($created) {
        removeTestSymlink($link);
    }
    rmdir($target);

    return $created;
}

beforeEach(function (): void {
    $this->workingDir = symlinkTestDirectory('pathwise_symlink_');
    $this->linkRoot = PathHelper::join($this->workingDir, 'public');
    $this->targetRoot = PathHelper::join($this->workingDir, 'storage');
    mkdir($this->linkRoot, 0700);
    mkdir($this->targetRoot, 0700);

    if (!supportsSymlinkCreation($this->workingDir)) {
        $this->markTestSkipped('Symbolic link creation is not available on this platform/runtime.');
    }

    $this->manager = new SafeSymlinkManager($this->linkRoot, $this->targetRoot);
});

afterEach(function (): void {
    if (!isset($this->workingDir) || !is_dir($this->workingDir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->workingDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            removeTestSymlink($item->getPathname());

            continue;
        }
        if ($item->isFile()) {
            unlink($item->getPathname());

            continue;
        }

        rmdir($item->getPathname());
    }

    rmdir($this->workingDir);
});

test('it creates a missing target directory and an idempotent safe symlink', function (): void {
    $created = $this->manager->create('assets', 'generated/assets', true);
    $status = $this->manager->status('assets', 'generated/assets');

    expect($created)->toBeTrue()
        ->and($this->manager->create('assets', 'generated/assets', true))->toBeFalse()
        ->and($status->exists)->toBeTrue()
        ->and($status->linked)->toBeTrue()
        ->and($status->matches)->toBeTrue()
        ->and($status->broken)->toBeFalse()
        ->and(is_dir(PathHelper::join($this->targetRoot, 'generated/assets')))->toBeTrue();
});

test('it rejects link and target paths outside their configured roots', function (): void {
    $outside = PathHelper::join($this->workingDir, 'outside');
    mkdir($outside, 0700);

    expect(fn () => $this->manager->create(PathHelper::join($outside, 'link'), 'inside', true))
        ->toThrow(PolicyViolationException::class, 'must remain inside')
        ->and(fn () => $this->manager->create('link', PathHelper::join($outside, 'target'), true))
        ->toThrow(PolicyViolationException::class, 'must remain inside');
});

test('it rejects parent-directory traversal in link and target paths', function (): void {
    expect(fn () => $this->manager->create('../escape', 'safe', true))
        ->toThrow(PolicyViolationException::class, 'parent-directory traversal')
        ->and(fn () => $this->manager->create('safe', '../escape', true))
        ->toThrow(PolicyViolationException::class, 'parent-directory traversal');
});

test('it rejects a symlinked link parent that escapes the allowed link root', function (): void {
    $outside = PathHelper::join($this->workingDir, 'outside-link-parent');
    mkdir($outside, 0700);
    symlink($outside, PathHelper::join($this->linkRoot, 'escape'));

    expect(fn () => $this->manager->create('escape/public-link', 'target', true))
        ->toThrow(PolicyViolationException::class, 'Symlink parent must remain inside');
});

test('it rejects a symlinked target ancestor that escapes the allowed target root', function (): void {
    $outside = PathHelper::join($this->workingDir, 'outside-target-parent');
    mkdir($outside, 0700);
    symlink($outside, PathHelper::join($this->targetRoot, 'escape'));

    expect(fn () => $this->manager->create('public-link', 'escape/generated', true))
        ->toThrow(PolicyViolationException::class, 'Symlink target ancestor must remain inside');
});

test('it never clobbers an existing non-link path', function (): void {
    $link = PathHelper::join($this->linkRoot, 'existing.txt');
    file_put_contents($link, 'keep-me');
    expect(fn () => $this->manager->create('existing.txt', 'target', true))
        ->toThrow(PolicyViolationException::class, 'already exists')
        ->and(file_get_contents($link))->toBe('keep-me')
        ->and(is_dir(PathHelper::join($this->targetRoot, 'target')))->toBeFalse();
});

test('it refuses to replace or remove a symlink that points elsewhere', function (): void {
    mkdir(PathHelper::join($this->targetRoot, 'first'), 0700);
    mkdir(PathHelper::join($this->targetRoot, 'second'), 0700);
    $this->manager->create('current', 'first');

    expect(fn () => $this->manager->create('current', 'second'))
        ->toThrow(PolicyViolationException::class, 'different symbolic link')
        ->and(fn () => $this->manager->remove('current', 'second'))
        ->toThrow(PolicyViolationException::class, 'does not match')
        ->and($this->manager->status('current', 'second')->matches)->toBeFalse()
        ->and($this->manager->remove('current', 'first'))->toBeTrue()
        ->and($this->manager->remove('current', 'first'))->toBeFalse();
});

test('it can safely identify and remove a broken link created for the expected target', function (): void {
    $this->manager->create('broken', 'disposable', true);
    rmdir(PathHelper::join($this->targetRoot, 'disposable'));

    $status = $this->manager->status('broken', 'disposable');

    expect($status->exists)->toBeTrue()
        ->and($status->linked)->toBeTrue()
        ->and($status->matches)->toBeTrue()
        ->and($status->broken)->toBeTrue()
        ->and($this->manager->remove('broken', 'disposable'))->toBeTrue();
});

test('it validates target-directory permissions', function (): void {
    expect(fn () => $this->manager->create('link', 'target', true, 01000))
        ->toThrow(InvalidArgumentException::class, 'permissions');
});
