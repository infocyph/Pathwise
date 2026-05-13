<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(80)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class FlysystemHelperBench
{
    private string $baseDir;

    private string $filePath;

    public function setUp(): void
    {
        $this->baseDir = PathHelper::normalize(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_bench_' . uniqid('', true));
        FlysystemHelper::createDirectory($this->baseDir);

        $this->filePath = PathHelper::join($this->baseDir, 'payload.txt');
        FlysystemHelper::write($this->filePath, str_repeat('Pathwise benchmark payload' . PHP_EOL, 128));
    }

    public function tearDown(): void
    {
        if (FlysystemHelper::directoryExists($this->baseDir)) {
            FlysystemHelper::deleteDirectory($this->baseDir);
        }
    }

    public function benchChecksumSha256(): void
    {
        FlysystemHelper::checksum($this->filePath, 'sha256');
    }

    public function benchGetMetadataSizeAndMtime(): void
    {
        FlysystemHelper::size($this->filePath);
        FlysystemHelper::lastModified($this->filePath);
    }

    public function benchReadFileContents(): void
    {
        FlysystemHelper::read($this->filePath);
    }
}
