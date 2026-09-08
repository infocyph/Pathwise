<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

function downloadChunkTempDirectory(): string
{
    $directory = PathHelper::join(sys_get_temp_dir(), 'pathwise_download_chunks_' . bin2hex(random_bytes(8)));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory '{$directory}'.");
    }

    return $directory;
}

beforeEach(function (): void {
    FlysystemHelper::reset();
    $this->workingDir = downloadChunkTempDirectory();
    $this->downloadProcessor = new DownloadProcessor();
    $this->downloadProcessor->setAllowedRoots([$this->workingDir]);
});

afterEach(function (): void {
    if (is_dir($this->workingDir)) {
        FlysystemHelper::deleteDirectory($this->workingDir);
    }

    FlysystemHelper::reset();
});

test('it yields a prepared download using the configured chunk size', function (): void {
    $path = PathHelper::join($this->workingDir, 'chunked.txt');
    file_put_contents($path, 'abcdefghij');
    $this->downloadProcessor->setChunkSize(4);

    $manifest = $this->downloadProcessor->prepareDownload($path);
    $chunks = iterator_to_array($this->downloadProcessor->streamChunks($manifest), false);

    expect($chunks)->toBe(['abcd', 'efgh', 'ij'])
        ->and(implode('', $chunks))->toBe('abcdefghij');
});

test('it yields only the exact prepared byte range', function (): void {
    $path = PathHelper::join($this->workingDir, 'range.txt');
    file_put_contents($path, '0123456789');
    $this->downloadProcessor->setChunkSize(2);

    $manifest = $this->downloadProcessor->prepareDownload($path, null, 'bytes=3-7');
    $chunks = iterator_to_array($this->downloadProcessor->streamChunks($manifest), false);

    expect($manifest->status)->toBe(206)
        ->and($chunks)->toBe(['34', '56', '7'])
        ->and(implode('', $chunks))->toBe('34567');
});

test('it models an empty prepared download as an empty chunk iterable', function (): void {
    $path = PathHelper::join($this->workingDir, 'empty.txt');
    touch($path);

    $manifest = $this->downloadProcessor->prepareDownload($path);

    expect(iterator_to_array($this->downloadProcessor->streamChunks($manifest), false))->toBe([]);
});

test('it revalidates download policy before streaming a prepared manifest', function (): void {
    $outside = PathHelper::join($this->workingDir, 'outside');
    $allowed = PathHelper::join($this->workingDir, 'allowed');
    mkdir($outside, 0700);
    mkdir($allowed, 0700);
    $path = PathHelper::join($outside, 'report.txt');
    file_put_contents($path, 'policy-content');

    $this->downloadProcessor->setAllowedRoots([]);
    $manifest = $this->downloadProcessor->prepareDownload($path);
    $this->downloadProcessor->setAllowedRoots([$allowed]);

    expect(fn () => iterator_to_array($this->downloadProcessor->streamChunks($manifest), false))
        ->toThrow(DownloadException::class, 'outside allowed roots');
});

test('it rejects a prepared manifest when source metadata becomes stale', function (): void {
    $path = PathHelper::join($this->workingDir, 'stale.txt');
    file_put_contents($path, 'original');
    $manifest = $this->downloadProcessor->prepareDownload($path);
    file_put_contents($path, 'original-mutated');
    clearstatcache(true, $path);

    expect(fn () => iterator_to_array($this->downloadProcessor->streamChunks($manifest), false))
        ->toThrow(DownloadException::class, 'metadata is stale');
});

test('disposing a partially consumed chunk generator releases the input file', function (): void {
    $path = PathHelper::join($this->workingDir, 'release.txt');
    $moved = PathHelper::join($this->workingDir, 'released.txt');
    file_put_contents($path, 'abcdefghij');
    $this->downloadProcessor->setChunkSize(4);

    $manifest = $this->downloadProcessor->prepareDownload($path);
    $chunks = $this->downloadProcessor->streamChunks($manifest);
    $chunks->rewind();

    expect($chunks->current())->toBe('abcd');

    unset($chunks);

    expect(rename($path, $moved))->toBeTrue()
        ->and(file_get_contents($moved))->toBe('abcdefghij');
});
