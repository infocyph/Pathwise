<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Pathwise\Retention\RetentionManager;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(50)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class DataManagementBench
{
    private string $baseDir;

    public function setUp(): void
    {
        $this->baseDir = PathHelper::normalize(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_data_ops_bench_' . uniqid('', true));
        FlysystemHelper::createDirectory($this->baseDir);

        // Build a deterministic mixed set with duplicates.
        for ($i = 0; $i < 8; $i++) {
            $content = $i < 4 ? 'duplicate-content' : 'unique-content-' . $i;
            FlysystemHelper::write(
                PathHelper::join($this->baseDir, sprintf('artifact_%02d.txt', $i)),
                $content,
            );
        }
    }

    public function tearDown(): void
    {
        if (FlysystemHelper::directoryExists($this->baseDir)) {
            FlysystemHelper::deleteDirectory($this->baseDir);
        }
    }

    public function benchApplyRetentionScanOnly(): void
    {
        RetentionManager::apply($this->baseDir, null, null, 'mtime');
    }

    public function benchBuildChecksumIndex(): void
    {
        ChecksumIndexer::buildIndex($this->baseDir, 'sha256');
    }

    public function benchFindDuplicates(): void
    {
        ChecksumIndexer::findDuplicates($this->baseDir, 'sha256');
    }
}
