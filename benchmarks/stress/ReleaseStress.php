<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks\Stress;

use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use PhpBench\Attributes as Bench;

/**
 * Explicit stress workloads excluded from PHPForge's default *Bench.php scan.
 *
 * Run with:
 * vendor/bin/phpbench run benchmarks/stress/ReleaseStress.php --bootstrap=vendor/autoload.php
 */
#[Bench\Iterations(1)]
#[Bench\Revs(1)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class ReleaseStress
{
    private string $baseDirectory;

    public function setUp(): void
    {
        $this->baseDirectory = PathHelper::join(sys_get_temp_dir(), 'pathwise_release_stress_' . uniqid('', true));
    }

    public function tearDown(): void
    {
        if (FlysystemHelper::directoryExists($this->baseDirectory)) {
            FlysystemHelper::deleteDirectory($this->baseDirectory);
        }
    }

    public function benchChunkAssembly1000ReverseArrival(): void
    {
        $uploadDirectory = PathHelper::join($this->baseDirectory, 'uploads');
        $temporaryDirectory = PathHelper::join($this->baseDirectory, 'chunks');
        $uploader = new UploadProcessor();
        $uploader->setDirectorySettings($uploadDirectory, false, $temporaryDirectory);
        for ($index = 999; $index >= 0; $index--) {
            $part = PathHelper::join($this->baseDirectory, "part-{$index}.tmp");
            FlysystemHelper::write($part, 'x');
            $uploader->processChunkUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => 1,
                'tmp_name' => $part,
                'name' => basename($part),
            ], 'stress_1000', $index, 1_000, 'assembled.txt');
        }
        $uploader->finalizeChunkUpload('stress_1000');
    }

    public function benchQueue1000(): void
    {
        $this->runQueueWorkload(1_000);
    }

    public function benchQueue10000(): void
    {
        $this->runQueueWorkload(10_000);
    }

    public function benchTransactionHundredUpdates(): void
    {
        $path = PathHelper::join($this->baseDirectory, 'transaction-100.bin');
        FlysystemHelper::write($path, str_repeat('0123456789abcdef', 512 * 1024));
        (new FileOperations($path))->transaction(static function (FileOperations $operations): void {
            for ($index = 0; $index < 100; $index++) {
                $operations->update(str_repeat((string) ($index % 10), 8 * 1024 * 1024));
            }
        });
    }

    private function runQueueWorkload(int $jobs): void
    {
        $queue = new FileJobQueue(PathHelper::join($this->baseDirectory, "queue-{$jobs}.json"), maxJobs: $jobs);
        for ($index = 0; $index < $jobs; $index++) {
            $queue->enqueue('benchmark', ['index' => $index]);
        }
        $queue->process(static function (): void {});
    }
}
