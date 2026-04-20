<?php

use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    FlysystemHelper::reset();
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

    FlysystemHelper::reset();
});

test('it throws when naming strategy is invalid', function () {
    expect(fn() => $this->uploadProcessor->setNamingStrategy('uuid'))
        ->toThrow(UploadException::class, 'Invalid naming strategy');
});

test('it throws when upload directory is not configured', function () {
    expect(fn() => $this->uploadProcessor->processUpload(['error' => UPLOAD_ERR_OK]))
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

    expect(fn() => $this->uploadProcessor->processUpload(['error' => [UPLOAD_ERR_OK]]))
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

    expect(fn() => $this->uploadProcessor->processUpload($file))
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

        expect(fn() => $this->uploadProcessor->processUpload($file))
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
    $this->uploadProcessor->setMalwareScanner(function (string $path, string $mime): bool {
        unset($path, $mime);

        return true;
    });

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

    $this->uploadProcessor->setMalwareScanner(function (string $path, string $mime): bool {
        unset($path, $mime);

        return false;
    });

    expect(fn() => $this->uploadProcessor->finalizeChunkUpload($uploadId))
        ->toThrow(UploadException::class, 'Malware scan failed');
});

test('it blocks upload when extension is blocked', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);

    $tmpFile = $this->uploadDir . DIRECTORY_SEPARATOR . uniqid('upload_blocked_', true) . '.tmp';
    file_put_contents($tmpFile, 'content');

    try {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'tmp_name' => $tmpFile,
            'name' => 'payload.php',
        ];

        expect(fn() => $this->uploadProcessor->processUpload($file))
            ->toThrow(UploadException::class, 'Blocked file extension');
    } finally {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }
});

test('it requires malware scanner when configured', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);
    $this->uploadProcessor->setRequireMalwareScan(true);

    $tmpFile = $this->uploadDir . DIRECTORY_SEPARATOR . uniqid('upload_scan_', true) . '.txt';
    file_put_contents($tmpFile, 'plain text');

    try {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'tmp_name' => $tmpFile,
            'name' => 'sample.txt',
        ];

        expect(fn() => $this->uploadProcessor->processUpload($file))
            ->toThrow(UploadException::class, 'Malware scanner is required but not configured');
    } finally {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }
});

test('it enforces strict content validation for extension and mime agreement', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir);
    $this->uploadProcessor->setStrictContentTypeValidation(true);

    $tmpFile = $this->uploadDir . DIRECTORY_SEPARATOR . uniqid('upload_strict_', true) . '.tmp';
    file_put_contents($tmpFile, 'not-a-real-image');

    try {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'tmp_name' => $tmpFile,
            'name' => 'avatar.png',
        ];

        expect(fn() => $this->uploadProcessor->processUpload($file))
            ->toThrow(UploadException::class, 'File content type does not match extension');
    } finally {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }
});

test('it enforces configured chunk count limit', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir, false, $this->uploadDir);
    $this->uploadProcessor->setChunkLimits(maxChunkCount: 2, maxChunkSize: 0);

    $tempChunkPath = $this->uploadDir . DIRECTORY_SEPARATOR . 'limit_count.part';
    file_put_contents($tempChunkPath, 'abc');

    try {
        expect(fn() => $this->uploadProcessor->processChunkUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempChunkPath),
            'tmp_name' => $tempChunkPath,
            'name' => 'limit_count.part',
        ], 'session_limit_count', 0, 3, 'merged.txt'))
            ->toThrow(UploadException::class, 'Total chunks exceed configured limit');
    } finally {
        if (file_exists($tempChunkPath)) {
            unlink($tempChunkPath);
        }
    }
});

test('it enforces configured chunk size limit', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir, false, $this->uploadDir);
    $this->uploadProcessor->setChunkLimits(maxChunkCount: 0, maxChunkSize: 2);

    $tempChunkPath = $this->uploadDir . DIRECTORY_SEPARATOR . 'limit_size.part';
    file_put_contents($tempChunkPath, 'abcd');

    try {
        expect(fn() => $this->uploadProcessor->processChunkUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempChunkPath),
            'tmp_name' => $tempChunkPath,
            'name' => 'limit_size.part',
        ], 'session_limit_size', 0, 1, 'merged.txt'))
            ->toThrow(FileSizeExceededException::class, 'Chunk exceeds configured size limit');
    } finally {
        if (file_exists($tempChunkPath)) {
            unlink($tempChunkPath);
        }
    }
});

test('it rejects unsafe upload ids in chunk flow', function () {
    $this->uploadProcessor->setDirectorySettings($this->uploadDir, false, $this->uploadDir);

    $tempChunkPath = $this->uploadDir . DIRECTORY_SEPARATOR . 'unsafe_id.part';
    file_put_contents($tempChunkPath, 'ok');

    try {
        expect(fn() => $this->uploadProcessor->processChunkUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempChunkPath),
            'tmp_name' => $tempChunkPath,
            'name' => 'unsafe_id.part',
        ], '../unsafe', 0, 1, 'merged.txt'))
            ->toThrow(UploadException::class, 'Invalid upload session id');
    } finally {
        if (file_exists($tempChunkPath)) {
            unlink($tempChunkPath);
        }
    }
});

test('it processes upload to a mounted filesystem path', function () {
    $mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('upload_mount_', true);
    mkdir($mountRoot, 0755, true);
    FlysystemHelper::mount('mnt', new Filesystem(new LocalFilesystemAdapter($mountRoot)));

    $this->uploadProcessor->setDirectorySettings('mnt://uploads');
    $this->uploadProcessor->setValidationSettings(['text/plain'], 1024 * 1024);

    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('upload_mount_file_', true) . '.txt';
    file_put_contents($tmpFile, 'mounted-upload-content');

    try {
        $destination = $this->uploadProcessor->processUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'tmp_name' => $tmpFile,
            'name' => 'note.txt',
        ]);

        expect($destination)->toStartWith('mnt://uploads/')
            ->and(FlysystemHelper::fileExists($destination))->toBeTrue()
            ->and(FlysystemHelper::read($destination))->toBe('mounted-upload-content');
    } finally {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        FlysystemHelper::unmount('mnt');
        FlysystemHelper::deleteDirectory($mountRoot);
    }
});

test('it finalizes chunk upload on a mounted filesystem path', function () {
    $mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('chunk_mount_', true);
    mkdir($mountRoot, 0755, true);
    FlysystemHelper::mount('mnt', new Filesystem(new LocalFilesystemAdapter($mountRoot)));

    $this->uploadProcessor->setDirectorySettings('mnt://uploads', false, 'mnt://tmp');
    $this->uploadProcessor->setValidationSettings(['text/plain'], 1024 * 1024);

    $uploadId = 'session_mounted_chunks';
    $parts = ['alpha ', 'beta ', 'gamma'];

    $tempParts = [];

    try {
        foreach ($parts as $index => $content) {
            $tmpPart = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid("chunk_mount_{$index}_", true) . '.part';
            file_put_contents($tmpPart, $content);
            $tempParts[] = $tmpPart;

            $this->uploadProcessor->processChunkUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpPart),
                'tmp_name' => $tmpPart,
                'name' => "chunk_{$index}.part",
            ], $uploadId, $index, count($parts), 'assembled.txt');
        }

        $destination = $this->uploadProcessor->finalizeChunkUpload($uploadId);

        expect($destination)->toStartWith('mnt://uploads/')
            ->and(FlysystemHelper::fileExists($destination))->toBeTrue()
            ->and(FlysystemHelper::read($destination))->toBe('alpha beta gamma');
    } finally {
        foreach ($tempParts as $part) {
            if (file_exists($part)) {
                unlink($part);
            }
        }

        FlysystemHelper::unmount('mnt');
        FlysystemHelper::deleteDirectory($mountRoot);
    }
});

test('it processes upload with default filesystem using relative paths', function () {
    $defaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('upload_default_', true);
    mkdir($defaultRoot, 0755, true);
    FlysystemHelper::setDefaultFilesystem(new Filesystem(new LocalFilesystemAdapter($defaultRoot)));

    $this->uploadProcessor->setDirectorySettings('uploads');
    $this->uploadProcessor->setValidationSettings(['text/plain'], 1024 * 1024);

    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('upload_default_file_', true) . '.txt';
    file_put_contents($tmpFile, 'default-filesystem-content');

    try {
        $destination = $this->uploadProcessor->processUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
            'tmp_name' => $tmpFile,
            'name' => 'relative.txt',
        ]);

        expect(str_starts_with(str_replace('\\', '/', $destination), 'uploads/'))->toBeTrue()
            ->and(FlysystemHelper::fileExists($destination))->toBeTrue()
            ->and(FlysystemHelper::read($destination))->toBe('default-filesystem-content');
    } finally {
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        FlysystemHelper::clearDefaultFilesystem();
        FlysystemHelper::deleteDirectory($defaultRoot);
    }
});
