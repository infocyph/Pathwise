<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

function uploadTrustDirectory(string $prefix): string
{
    $directory = PathHelper::join(sys_get_temp_dir(), $prefix . bin2hex(random_bytes(8)));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory '{$directory}'.");
    }

    return $directory;
}

beforeEach(function (): void {
    FlysystemHelper::reset();
    $this->uploadRoot = uploadTrustDirectory('pathwise_trust_upload_');
    $this->stagingRoot = uploadTrustDirectory('pathwise_trust_stage_');
    $this->sourceRoot = uploadTrustDirectory('pathwise_trust_source_');
});

afterEach(function (): void {
    FlysystemHelper::reset();
    foreach ([$this->uploadRoot, $this->stagingRoot, $this->sourceRoot] as $directory) {
        if (is_dir($directory) && !is_link($directory)) {
            FlysystemHelper::deleteDirectory($directory);
        }
    }
});

test('strict untrusted profile applies finite and server-controlled defaults', function (): void {
    $processor = new UploadProcessor();
    $processor->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
    $info = $processor->getInfo();

    expect($processor->getTrustProfile())->toBe(UploadTrustProfile::UNTRUSTED_DATA)
        ->and($info['maxChunkCount'])->toBeGreaterThan(0)
        ->and($info['maxChunkSize'])->toBeGreaterThan(0)
        ->and($info['namingStrategy'])->toBe('hash')
        ->and($info['strictContentTypeValidation'])->toBeTrue();
});

test('strict chunk flow refuses unlimited limits reintroduced by the caller', function (): void {
    $processor = new UploadProcessor();
    $processor->setDirectorySettings($this->uploadRoot, false, $this->stagingRoot);
    $processor->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
    $processor->setChunkLimits(0, 0);

    $source = PathHelper::join($this->sourceRoot, 'chunk.part');
    file_put_contents($source, 'chunk');

    expect(fn () => $processor->processChunkUpload([
        'error' => UPLOAD_ERR_OK,
        'size' => 1,
        'tmp_name' => $source,
        'name' => 'chunk.part',
    ], 'strict_session', 0, 1, 'assembled.txt'))
        ->toThrow(UploadException::class, 'finite chunk count and size limits');
});

test('strict local publication produces a non executable private file', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $processor = new UploadProcessor();
    $processor->setDirectorySettings($this->uploadRoot, false, $this->stagingRoot);
    $processor->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
    $processor->setValidationSettings(['text/plain'], 1024 * 1024);

    $source = PathHelper::join($this->sourceRoot, 'private.txt');
    file_put_contents($source, 'private-content');
    chmod($source, 0777);

    $destination = $processor->ingestFile([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($source),
        'tmp_name' => $source,
        'name' => 'private.txt',
    ]);
    $mode = fileperms($destination);

    expect($mode)->toBeInt()
        ->and($mode & 0777)->toBe(0600)
        ->and(is_executable($destination))->toBeFalse();
});

test('strict publication never uses the client filename as the destination path', function (): void {
    $processor = new UploadProcessor();
    $processor->setDirectorySettings($this->uploadRoot, false, $this->stagingRoot);
    $processor->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
    $processor->setValidationSettings(['text/plain'], 1024 * 1024);

    $source = PathHelper::join($this->sourceRoot, 'source.txt');
    file_put_contents($source, 'server-controlled-name');
    $clientFilename = '$(touch-owned); user report.txt';

    $destination = $processor->ingestFile([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($source),
        'tmp_name' => $source,
        'name' => $clientFilename,
    ]);

    expect(basename($destination))->not->toBe($clientFilename)
        ->and(basename($destination))->not->toContain('touch-owned', 'user report')
        ->and(pathinfo($destination, PATHINFO_EXTENSION))->toBe('txt')
        ->and(file_get_contents($destination))->toBe('server-controlled-name');
});

test('publication rejects a date directory symlink that escapes the upload root', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    $outside = uploadTrustDirectory('pathwise_trust_outside_');
    $yearLink = PathHelper::join($this->uploadRoot, date('Y'));
    symlink($outside, $yearLink);

    try {
        $processor = new UploadProcessor();
        $processor->setDirectorySettings($this->uploadRoot, true, $this->stagingRoot);
        $processor->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
        $processor->setValidationSettings(['text/plain'], 1024 * 1024);

        $source = PathHelper::join($this->sourceRoot, 'escape.txt');
        file_put_contents($source, 'escape-check');

        expect(fn () => $processor->ingestFile([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
            'tmp_name' => $source,
            'name' => 'escape.txt',
        ]))->toThrow(UploadException::class, 'escaped the configured root')
            ->and(is_dir(PathHelper::join($outside, date('m'))))->toBeFalse();
    } finally {
        if (is_link($yearLink)) {
            unlink($yearLink);
        }
        if (is_dir($outside)) {
            FlysystemHelper::deleteDirectory($outside);
        }
    }
});

test('strict policy keeps adapter publication capability based', function (): void {
    $mountRoot = uploadTrustDirectory('pathwise_trust_mount_');
    FlysystemHelper::mount('secure', new Filesystem(new LocalFilesystemAdapter($mountRoot)));

    try {
        $processor = new UploadProcessor();
        $processor->setDirectorySettings('secure://uploads', false, $this->stagingRoot);
        $processor->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
        $processor->setValidationSettings(['text/plain'], 1024 * 1024);

        $source = PathHelper::join($this->sourceRoot, 'adapter.txt');
        file_put_contents($source, 'adapter-content');
        $destination = $processor->ingestFile([
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
            'tmp_name' => $source,
            'name' => 'adapter.txt',
        ]);

        expect($destination)->toStartWith('secure://uploads/')
            ->and(FlysystemHelper::read($destination))->toBe('adapter-content');
    } finally {
        FlysystemHelper::unmount('secure');
        if (is_dir($mountRoot)) {
            FlysystemHelper::deleteDirectory($mountRoot);
        }
    }
});
