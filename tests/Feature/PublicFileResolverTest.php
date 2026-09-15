<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\PublicFileResolver;
use Infocyph\Pathwise\StreamHandler\PublicFileSymlinkPolicy;

beforeEach(function (): void {
    $this->publicFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_public_' . bin2hex(random_bytes(8));
    $this->publicRoot = $this->publicFixture . DIRECTORY_SEPARATOR . 'public';
    $this->outsideRoot = $this->publicFixture . DIRECTORY_SEPARATOR . 'private';

    foreach ([$this->publicRoot, $this->outsideRoot] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create public resolver fixture: {$directory}");
        }
    }

    mkdir($this->publicRoot . DIRECTORY_SEPARATOR . 'assets', 0700);
    file_put_contents($this->publicRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.txt', 'public-data');
    file_put_contents($this->outsideRoot . DIRECTORY_SEPARATOR . 'secret.txt', 'private-data');
});

afterEach(function (): void {
    if (!is_dir($this->publicFixture)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->publicFixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($this->publicFixture);
});

test('public resolver returns canonical trusted metadata for a relative file', function (): void {
    $resolution = (new PublicFileResolver())->resolve($this->publicRoot, 'assets/app.txt');

    expect($resolution->path)->toBe(realpath($this->publicRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.txt'))
        ->and($resolution->relativePath)->toBe('assets/app.txt')
        ->and($resolution->size)->toBe(strlen('public-data'))
        ->and($resolution->lastModified)->toBeGreaterThan(0)
        ->and($resolution->mimeType)->not->toBe('');
});

test('resolved public artifact composes with download preparation without reparsing a request path', function (): void {
    $resolution = (new PublicFileResolver())->resolve($this->publicRoot, 'assets/app.txt');
    $prepared = (new DownloadProcessor())->prepareDownload(
        $resolution->path,
        rangeHeader: 'bytes=0-5',
    );

    expect($prepared->path)->toBe($resolution->path)
        ->and($prepared->size)->toBe($resolution->size)
        ->and($prepared->lastModified)->toBe($resolution->lastModified)
        ->and($prepared->status)->toBe(206)
        ->and($prepared->range->contentLength)->toBe(6);
});

test('public resolver rejects traversal and absolute candidates', function (): void {
    $resolver = new PublicFileResolver();

    expect(fn() => $resolver->resolve($this->publicRoot, '../private/secret.txt'))
        ->toThrow(DownloadException::class)
        ->and(fn() => $resolver->resolve($this->publicRoot, $this->outsideRoot . DIRECTORY_SEPARATOR . 'secret.txt'))
        ->toThrow(DownloadException::class);
});

test('public resolver rejects symlink escapes even when in-root symlinks are allowed', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $link = $this->publicRoot . DIRECTORY_SEPARATOR . 'escape.txt';
    symlink($this->outsideRoot . DIRECTORY_SEPARATOR . 'secret.txt', $link);

    expect(fn() => (new PublicFileResolver())->resolve(
        $this->publicRoot,
        'escape.txt',
        PublicFileSymlinkPolicy::ALLOW_WITHIN_ROOT,
    ))->toThrow(DownloadException::class);
});

test('public resolver applies an explicit in-root symlink policy', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $link = $this->publicRoot . DIRECTORY_SEPARATOR . 'alias.txt';
    symlink($this->publicRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.txt', $link);
    $resolver = new PublicFileResolver();

    expect(fn() => $resolver->resolve($this->publicRoot, 'alias.txt'))
        ->toThrow(DownloadException::class);

    $resolution = $resolver->resolve(
        $this->publicRoot,
        'alias.txt',
        PublicFileSymlinkPolicy::ALLOW_WITHIN_ROOT,
    );

    expect($resolution->path)->toBe(realpath($this->publicRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.txt'));
});
