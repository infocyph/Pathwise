<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(4)]
#[Bench\Revs(40)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class StreamHandlerBench
{
    private string $baseDir;

    private string $downloadFilePath;

    private DownloadProcessor $downloadProcessor;

    private string $uploadDir;

    private UploadProcessor $uploadProcessor;

    public function setUp(): void
    {
        $this->baseDir = PathHelper::normalize(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_stream_bench_' . uniqid('', true));
        $this->uploadDir = PathHelper::join($this->baseDir, 'uploads');
        $downloadsDir = PathHelper::join($this->baseDir, 'downloads');

        FlysystemHelper::createDirectory($this->uploadDir);
        FlysystemHelper::createDirectory($downloadsDir);

        $this->downloadFilePath = PathHelper::join($downloadsDir, 'dataset.txt');
        FlysystemHelper::write($this->downloadFilePath, str_repeat('download-content' . PHP_EOL, 64));

        $this->uploadProcessor = new UploadProcessor();
        $this->uploadProcessor->setDirectorySettings($this->uploadDir, false);
        $this->uploadProcessor->setValidationSettings([], 2 * 1024 * 1024);

        $this->downloadProcessor = new DownloadProcessor();
        $this->downloadProcessor->setAllowedRoots([$this->baseDir]);
    }

    public function tearDown(): void
    {
        if (FlysystemHelper::directoryExists($this->baseDir)) {
            FlysystemHelper::deleteDirectory($this->baseDir);
        }
    }

    public function benchPrepareDownloadMetadata(): void
    {
        $this->downloadProcessor->prepareDownload($this->downloadFilePath, 'dataset.txt', null);
    }

    public function benchProcessUpload(): void
    {
        $tmpUpload = tempnam(sys_get_temp_dir(), 'pathwise_upload_bench_');
        if (!is_string($tmpUpload)) {
            return;
        }

        file_put_contents($tmpUpload, str_repeat('upload-payload', 128));

        try {
            $destination = $this->uploadProcessor->processUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpUpload) ?: 0,
                'tmp_name' => $tmpUpload,
                'name' => 'payload.txt',
            ]);
            FlysystemHelper::delete($destination);
        } finally {
            if (is_file($tmpUpload)) {
                unlink($tmpUpload);
            }
        }
    }

    public function benchStreamDownload(): void
    {
        $output = fopen('php://temp', 'w+b');
        if (!is_resource($output)) {
            return;
        }

        try {
            $this->downloadProcessor->streamDownload($this->downloadFilePath, $output);
        } finally {
            fclose($output);
        }
    }
}
