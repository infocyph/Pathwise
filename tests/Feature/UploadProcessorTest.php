<?php

use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;

beforeEach(function () {
    $this->uploadProcessor = new UploadProcessor();
    $this->uploadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('upload_dir_', true);
});

afterEach(function () {
    if (is_dir($this->uploadDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->uploadDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->uploadDir);
    }
});

test('it throws when naming strategy is invalid', function () {
    expect(fn () => $this->uploadProcessor->setNamingStrategy('uuid'))
        ->toThrow(UploadException::class, 'Invalid naming strategy');
});

test('it throws when upload directory is not configured', function () {
    expect(fn () => $this->uploadProcessor->processUpload(['error' => UPLOAD_ERR_OK]))
        ->toThrow(UploadException::class, 'Upload directory is not set');
});

test('it creates upload directory when configured', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);

    $info = $this->uploadProcessor->getInfo();
    expect(is_dir($this->uploadDir))
        ->toBeTrue()
        ->and($info['uploadDir'])->toContain($this->uploadDir);
});

test('it throws for invalid upload parameters', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);

    expect(fn () => $this->uploadProcessor->processUpload(['error' => [UPLOAD_ERR_OK]]))
        ->toThrow(UploadException::class, 'Invalid file upload parameters');
});

test('it throws when file exceeds configured size', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);
    $this->uploadProcessor->setValidationSettings(['text/plain'], 1);

    $file = [
        'error' => UPLOAD_ERR_OK,
        'size' => 10,
        'tmp_name' => __FILE__,
        'name' => 'oversized.txt',
    ];

    expect(fn () => $this->uploadProcessor->processUpload($file))
        ->toThrow(FileSizeExceededException::class, 'Exceeded file size limit');
});

test('it throws for disallowed mime type before moving upload', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);
    $this->uploadProcessor->setValidationSettings(['image/png'], 1024 * 1024);

    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('upload_mime_', true) . '.txt';
    file_put_contents($tmpFile, 'plain text');

    try {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'tmp_name' => $tmpFile,
            'name' => 'plain.txt',
        ];

        expect(fn () => $this->uploadProcessor->processUpload($file))
            ->toThrow(UploadException::class, 'Invalid file format');
    } finally {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }
});

test('it configures validation profile presets', function () {
    $this->uploadProcessor->setValidationProfile('document');
    $info = $this->uploadProcessor->getInfo();

    expect($this->uploadProcessor->getValidationProfiles())->toContain('image', 'video', 'document')
        ->and($info['validationProfile'])->toBe('document')
        ->and($info['maxFileSize'])->toBeGreaterThan(1);
});

test('it supports chunked upload and finalize flow', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir, false, $this->uploadDir);
    $this->uploadProcessor->setValidationProfile('document');

    $uploadId = 'session_123';
    $parts = ['hello ', 'chunked ', 'world'];

    foreach ($parts as $index => $partContent) {
        $tempChunkPath = $this->uploadDir . DIRECTORY_SEPARATOR . "chunk_{$index}.part";
        file_put_contents($tempChunkPath, $partContent);

        $result = $this->uploadProcessor->processChunkUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempChunkPath),
            'tmp_name' => $tempChunkPath,
            'name' => "chunk_{$index}.part",
        ], $uploadId, $index, count($parts), 'merged.txt');

        expect($result['receivedChunks'])->toBe($index + 1);
    }

    $finalPath = $this->uploadProcessor->finalizeChunkUpload($uploadId);
    expect(is_file($finalPath))->toBeTrue()
        ->and(file_get_contents($finalPath))->toBe('hello chunked world');
});

test('it exposes malware scanner state in info', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);
    $this->uploadProcessor->setMalwareScanner(fn (string $_path, string $_mime): bool => true);

    expect($this->uploadProcessor->getInfo()['hasMalwareScanner'])->toBeTrue();
});

test('it blocks finalize when malware scan fails', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir, false, $this->uploadDir);
    $this->uploadProcessor->setValidationProfile('document');

    $uploadId = 'session_malware';
    $tempChunkPath = $this->uploadDir . DIRECTORY_SEPARATOR . 'chunk.part';
    file_put_contents($tempChunkPath, 'unsafe-content');

    $this->uploadProcessor->processChunkUpload([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tempChunkPath),
        'tmp_name' => $tempChunkPath,
        'name' => 'chunk.part',
    ], $uploadId, 0, 1, 'merged.txt');

    $this->uploadProcessor->setMalwareScanner(fn (string $_path, string $_mime): bool => false);

    expect(fn () => $this->uploadProcessor->finalizeChunkUpload($uploadId))
        ->toThrow(UploadException::class, 'Malware scan failed');
});
