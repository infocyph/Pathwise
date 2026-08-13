<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\Core\SyncComparison;
use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\FileManager\SafeFileReader;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(1)]
#[Bench\Revs(1)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class ReleaseWorkloadsBench
{
    private string $baseDirectory;

    private string $largeFile;

    private string $sourceDirectory;

    public function setUp(): void
    {
        $this->baseDirectory = PathHelper::join(sys_get_temp_dir(), 'pathwise_release_bench_' . uniqid('', true));
        $this->sourceDirectory = PathHelper::join($this->baseDirectory, 'source');
        $this->largeFile = PathHelper::join($this->baseDirectory, 'large.bin');
    }

    public function tearDown(): void
    {
        if (FlysystemHelper::directoryExists($this->baseDirectory)) {
            FlysystemHelper::deleteDirectory($this->baseDirectory);
        }
    }

    public function benchChunkAssembly100(): void
    {
        $this->benchmarkChunkAssembly(100);
    }

    public function benchChunkAssembly1000ReverseArrival(): void
    {
        $this->benchmarkChunkAssembly(1_000, true);
    }

    public function benchQueue100(): void
    {
        $this->benchmarkQueue(100);
    }

    public function benchQueue1000(): void
    {
        $this->benchmarkQueue(1_000);
    }

    public function benchQueue10000(): void
    {
        $this->benchmarkQueue(10_000);
    }

    public function benchReader128KiB(): void
    {
        $this->consumeReader(131_072);
    }

    public function benchReader64KiB(): void
    {
        $this->consumeReader(65_536);
    }

    public function benchReader8KiB(): void
    {
        $this->consumeReader(8_192);
    }

    public function benchSyncChecksum1000Files(): void
    {
        $this->benchmarkSync(SyncComparison::CHECKSUM);
    }

    public function benchSyncSize1000Files(): void
    {
        $this->benchmarkSync(SyncComparison::SIZE);
    }

    public function benchSyncSizeAndModifiedTime1000Files(): void
    {
        $this->benchmarkSync(SyncComparison::SIZE_AND_MODIFIED_TIME);
    }

    public function benchTransactionHundredUpdates(): void
    {
        $this->benchmarkTransaction(100);
    }

    public function benchTransactionOneUpdate(): void
    {
        $this->benchmarkTransaction(1);
    }

    public function benchTransactionTenUpdates(): void
    {
        $this->benchmarkTransaction(10);
    }

    private function benchmarkChunkAssembly(int $chunks, bool $reverse = false): void
    {
        $uploadDirectory = PathHelper::join($this->baseDirectory, 'uploads-' . $chunks);
        $temporaryDirectory = PathHelper::join($this->baseDirectory, 'chunks-' . $chunks);
        $uploader = new UploadProcessor();
        $uploader->setDirectorySettings($uploadDirectory, false, $temporaryDirectory);
        $indexes = range(0, $chunks - 1);
        if ($reverse) {
            $indexes = array_reverse($indexes);
        }
        foreach ($indexes as $index) {
            $part = PathHelper::join($this->baseDirectory, "part-{$chunks}-{$index}.tmp");
            FlysystemHelper::write($part, 'x');
            $uploader->processChunkUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => 1,
                'tmp_name' => $part,
                'name' => basename($part),
            ], "bench_{$chunks}", $index, $chunks, 'assembled.txt');
        }
        $uploader->finalizeChunkUpload("bench_{$chunks}");
    }

    private function benchmarkQueue(int $jobs): void
    {
        $queue = new FileJobQueue(PathHelper::join($this->baseDirectory, "queue-{$jobs}.json"), maxJobs: $jobs);
        for ($index = 0; $index < $jobs; $index++) {
            $queue->enqueue('benchmark', ['index' => $index]);
        }
        $queue->process(static function (): void {});
    }

    private function benchmarkSync(SyncComparison $comparison): void
    {
        FlysystemHelper::createDirectory($this->sourceDirectory);
        for ($index = 0; $index < 1_000; $index++) {
            FlysystemHelper::write(
                PathHelper::join($this->sourceDirectory, sprintf('entry-%04d.txt', $index)),
                str_repeat((string) ($index % 10), 128),
            );
        }
        $target = PathHelper::join($this->baseDirectory, 'sync-' . $comparison->name);
        (new DirectoryOperations($this->sourceDirectory))->syncTo($target, true, null, $comparison);
    }

    private function benchmarkTransaction(int $updates): void
    {
        $this->createLargeFile();
        $path = PathHelper::join($this->baseDirectory, "transaction-{$updates}.bin");
        FlysystemHelper::copy($this->largeFile, $path);
        (new FileOperations($path))->transaction(static function (FileOperations $operations) use ($updates): void {
            for ($index = 0; $index < $updates; $index++) {
                $operations->update(str_repeat((string) ($index % 10), 8 * 1024 * 1024));
            }
        });
    }

    private function consumeReader(int $chunkSize): void
    {
        $this->createLargeFile();
        foreach ((new SafeFileReader($this->largeFile))->chunks($chunkSize) as $chunk) {
            strlen($chunk);
        }

        $download = new DownloadProcessor();
        $download->setChunkSize($chunkSize);
        $output = fopen('php://temp', 'w+b');
        if (is_resource($output)) {
            $download->streamDownload($this->largeFile, $output);
            fclose($output);
        }
    }

    private function createLargeFile(): void
    {
        FlysystemHelper::write($this->largeFile, str_repeat('0123456789abcdef', 512 * 1024));
    }
}
