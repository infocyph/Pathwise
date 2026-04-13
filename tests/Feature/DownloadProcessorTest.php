<?php

use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\Exceptions\FileNotFoundException;
use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    FlysystemHelper::reset();
    $this->downloadProcessor = new DownloadProcessor();
    $this->workingDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pathwise_download_', true);
    mkdir($this->workingDir, 0777, true);
});

afterEach(function () {
    if (!is_dir($this->workingDir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->workingDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($this->workingDir);
    FlysystemHelper::reset();
});

test('it prepares secure download metadata for a local file', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'report.txt';
    file_put_contents($path, 'secure-content');

    $this->downloadProcessor->setAllowedRoots([$this->workingDir]);
    $manifest = $this->downloadProcessor->prepareDownload($path, 'report final.txt');

    expect($manifest['status'])->toBe(200)
        ->and($manifest['contentLength'])->toBe(strlen('secure-content'))
        ->and($manifest['fileName'])->toBe('report final.txt')
        ->and($manifest['headers'])->toHaveKeys([
            'Accept-Ranges',
            'Cache-Control',
            'Content-Disposition',
            'Content-Length',
            'Content-Type',
            'ETag',
            'Last-Modified',
            'X-Content-Type-Options',
        ]);
});

test('it blocks downloads outside allowed roots', function () {
    $allowedRoot = $this->workingDir . DIRECTORY_SEPARATOR . 'allowed';
    $outsideRoot = $this->workingDir . DIRECTORY_SEPARATOR . 'outside';
    mkdir($allowedRoot, 0777, true);
    mkdir($outsideRoot, 0777, true);

    $path = $outsideRoot . DIRECTORY_SEPARATOR . 'file.txt';
    file_put_contents($path, 'data');

    $this->downloadProcessor->setAllowedRoots([$allowedRoot]);

    expect(fn() => $this->downloadProcessor->prepareDownload($path))
        ->toThrow(DownloadException::class, 'Download path is outside allowed roots');
});

test('it blocks hidden files by default', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . '.secret.txt';
    file_put_contents($path, 'hidden');

    expect(fn() => $this->downloadProcessor->prepareDownload($path))
        ->toThrow(DownloadException::class, 'Hidden file downloads are blocked');
});

test('it supports disabling hidden file blocking', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . '.secret.txt';
    file_put_contents($path, 'hidden');

    $this->downloadProcessor->setBlockHiddenFiles(false);
    $manifest = $this->downloadProcessor->prepareDownload($path);

    expect($manifest['status'])->toBe(200);
});

test('it blocks disallowed download extensions', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'payload.php';
    file_put_contents($path, '<?php echo "x";');

    expect(fn() => $this->downloadProcessor->prepareDownload($path))
        ->toThrow(DownloadException::class, 'Blocked file extension for download');
});

test('it enforces extension allowlists', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'archive.zip';
    file_put_contents($path, 'zipdata');

    $this->downloadProcessor->setExtensionPolicy(['txt'], ['php', 'phtml']);

    expect(fn() => $this->downloadProcessor->prepareDownload($path))
        ->toThrow(DownloadException::class, 'File extension is not allowed for download');
});

test('it sanitizes unsafe download file names', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'report.txt';
    file_put_contents($path, 'payload');

    $manifest = $this->downloadProcessor->prepareDownload($path, '..\\../evil".txt');

    expect($manifest['fileName'])->not->toContain('/')
        ->and($manifest['fileName'])->not->toContain('\\')
        ->and($manifest['fileName'])->not->toContain('"')
        ->and($manifest['fileName'])->toEndWith('.txt')
        ->and($manifest['headers']['Content-Disposition'])->toContain('filename=');
});

test('it returns partial metadata for valid byte ranges', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'range.txt';
    file_put_contents($path, 'hello-world');

    $manifest = $this->downloadProcessor->prepareDownload($path, null, 'bytes=6-10');

    expect($manifest['status'])->toBe(206)
        ->and($manifest['rangeStart'])->toBe(6)
        ->and($manifest['rangeEnd'])->toBe(10)
        ->and($manifest['contentLength'])->toBe(5)
        ->and($manifest['headers']['Content-Range'])->toBe('bytes 6-10/11');
});

test('it rejects invalid byte ranges', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'range.txt';
    file_put_contents($path, 'hello-world');

    expect(fn() => $this->downloadProcessor->prepareDownload($path, null, 'bytes=99-100'))
        ->toThrow(DownloadException::class, 'Invalid range header');
});

test('it ignores range headers when range support is disabled', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'range.txt';
    file_put_contents($path, 'hello-world');

    $this->downloadProcessor->setRangeRequestsEnabled(false);
    $manifest = $this->downloadProcessor->prepareDownload($path, null, 'bytes=1-3');

    expect($manifest['status'])->toBe(200)
        ->and($manifest['contentLength'])->toBe(11)
        ->and($manifest['headers']['Accept-Ranges'])->toBe('none')
        ->and($manifest['headers'])->not->toHaveKey('Content-Range');
});

test('it streams complete download content', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'content.txt';
    file_put_contents($path, 'streamed-content');

    $output = fopen('php://temp', 'rb+');
    $manifest = $this->downloadProcessor->streamDownload($path, $output);
    rewind($output);
    $downloaded = stream_get_contents($output);
    fclose($output);

    expect($manifest['status'])->toBe(200)
        ->and($manifest['bytesSent'])->toBe(strlen('streamed-content'))
        ->and($downloaded)->toBe('streamed-content');
});

test('it streams ranged download content', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'content.txt';
    file_put_contents($path, 'streamed-content');

    $output = fopen('php://temp', 'rb+');
    $manifest = $this->downloadProcessor->streamDownload($path, $output, null, 'bytes=9-15');
    rewind($output);
    $downloaded = stream_get_contents($output);
    fclose($output);

    expect($manifest['status'])->toBe(206)
        ->and($manifest['bytesSent'])->toBe(7)
        ->and($downloaded)->toBe('content');
});

test('it enforces maximum download size', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'large.txt';
    file_put_contents($path, '1234567890');

    $this->downloadProcessor->setMaxDownloadSize(5);

    expect(fn() => $this->downloadProcessor->prepareDownload($path))
        ->toThrow(FileSizeExceededException::class, 'Download exceeds configured size limit');
});

test('it throws when file does not exist', function () {
    $path = $this->workingDir . DIRECTORY_SEPARATOR . 'missing.txt';

    expect(fn() => $this->downloadProcessor->prepareDownload($path))
        ->toThrow(FileNotFoundException::class);
});

test('it prepares and streams downloads from a mounted filesystem path', function () {
    $mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('download_mount_', true);
    mkdir($mountRoot, 0755, true);
    FlysystemHelper::mount('mnt', new Filesystem(new LocalFilesystemAdapter($mountRoot)));

    $path = 'mnt://downloads/report.txt';
    FlysystemHelper::write($path, 'mounted-download-content');
    $this->downloadProcessor->setAllowedRoots(['mnt://downloads']);

    try {
        $manifest = $this->downloadProcessor->prepareDownload($path, 'report.txt');

        $output = fopen('php://temp', 'rb+');
        $streamedManifest = $this->downloadProcessor->streamDownload($path, $output);
        rewind($output);
        $downloaded = stream_get_contents($output);
        fclose($output);

        expect($manifest['status'])->toBe(200)
            ->and($manifest['contentLength'])->toBe(strlen('mounted-download-content'))
            ->and($streamedManifest['bytesSent'])->toBe(strlen('mounted-download-content'))
            ->and($downloaded)->toBe('mounted-download-content');
    } finally {
        FlysystemHelper::unmount('mnt');
        FlysystemHelper::deleteDirectory($mountRoot);
    }
});

test('it supports relative download paths with a default filesystem', function () {
    $defaultRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('download_default_', true);
    mkdir($defaultRoot, 0755, true);
    FlysystemHelper::setDefaultFilesystem(new Filesystem(new LocalFilesystemAdapter($defaultRoot)));

    $path = 'docs/relative.txt';
    FlysystemHelper::write($path, 'default-download-content');
    $this->downloadProcessor->setAllowedRoots(['docs']);

    try {
        $output = fopen('php://temp', 'rb+');
        $manifest = $this->downloadProcessor->streamDownload($path, $output);
        rewind($output);
        $downloaded = stream_get_contents($output);
        fclose($output);

        expect($manifest['status'])->toBe(200)
            ->and($manifest['bytesSent'])->toBe(strlen('default-download-content'))
            ->and($downloaded)->toBe('default-download-content');
    } finally {
        FlysystemHelper::clearDefaultFilesystem();
        FlysystemHelper::deleteDirectory($defaultRoot);
    }
});
