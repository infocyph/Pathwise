<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\FileManager\SafeFileReader;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\Observability\PartitionedAuditSink;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(3)]
#[Bench\Revs(5)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class WorkflowContractsBench
{
    private string $baseDir;

    private string $largeFile;

    private string $sourceDir;

    private string $syncTarget;

    public function setUp(): void
    {
        $this->baseDir = PathHelper::join(sys_get_temp_dir(), 'pathwise_contract_bench_' . uniqid('', true));
        $this->sourceDir = PathHelper::join($this->baseDir, 'source');
        $this->syncTarget = PathHelper::join($this->baseDir, 'sync-target');
        $auditRoot = PathHelper::join($this->baseDir, 'audit-store');
        FlysystemHelper::createDirectory($this->sourceDir);
        FlysystemHelper::createDirectory($this->syncTarget);
        FlysystemHelper::createDirectory($auditRoot);

        for ($index = 0; $index < 1_000; $index++) {
            FlysystemHelper::write(
                PathHelper::join($this->sourceDir, sprintf('entry-%04d.txt', $index)),
                str_repeat((string) ($index % 10), 256),
            );
        }

        $this->largeFile = PathHelper::join($this->baseDir, 'large.bin');
        FlysystemHelper::write($this->largeFile, str_repeat('0123456789abcdef', 512 * 1024));
        FlysystemHelper::mount('bench-audit', new Filesystem(new LocalFilesystemAdapter($auditRoot)));
    }

    public function tearDown(): void
    {
        FlysystemHelper::reset();
        if (FlysystemHelper::directoryExists($this->baseDir)) {
            FlysystemHelper::deleteDirectory($this->baseDir);
        }
    }

    public function benchDirectorySynchronization(): void
    {
        (new DirectoryOperations($this->sourceDir))->syncTo($this->syncTarget, true);
    }

    public function benchLargeDirectoryTraversal(): void
    {
        foreach (FlysystemHelper::listContentsListing($this->sourceDir, true) as $entry) {
            $entry->path();
        }
    }

    public function benchLargeNativeAppend(): void
    {
        (new FileOperations($this->largeFile))->append(str_repeat('a', 4_096), false);
    }

    public function benchNativeFileCopy(): void
    {
        $destination = PathHelper::join($this->baseDir, 'native-copy-' . uniqid('', true) . '.bin');
        (new FileOperations($this->largeFile))->setExecutionStrategy(ExecutionStrategy::NATIVE)->copy($destination);
    }

    public function benchPartitionedMountedAuditWrites(): void
    {
        $audit = new AuditTrail(new PartitionedAuditSink('bench-audit://events'));
        for ($index = 0; $index < 25; $index++) {
            $audit->log('benchmark', ['index' => $index, 'path' => $this->largeFile]);
        }
    }

    public function benchPhpCompressionAndDecompression(): void
    {
        $archive = PathHelper::join($this->baseDir, 'archive-' . uniqid('', true) . '.zip');
        $destination = PathHelper::join($this->baseDir, 'extract-' . uniqid('', true));
        $compression = (new FileCompression($archive, true))->setExecutionStrategy(ExecutionStrategy::PHP);
        $compression->compress($this->sourceDir)->save();
        (new FileCompression($archive))->setExecutionStrategy(ExecutionStrategy::PHP)->decompress($destination);
    }

    public function benchPhpFileCopy(): void
    {
        $destination = PathHelper::join($this->baseDir, 'php-copy-' . uniqid('', true) . '.bin');
        (new FileOperations($this->largeFile))->setExecutionStrategy(ExecutionStrategy::PHP)->copy($destination);
    }

    public function benchStreamingRead(): void
    {
        foreach ((new SafeFileReader($this->largeFile))->chunks(65_536) as $chunk) {
            strlen($chunk);
        }
    }
}
