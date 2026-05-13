<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(120)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class FileAndDirectoryBench
{
    private string $baseDir;

    private string $expectedChecksum;

    private string $filePath;

    public function setUp(): void
    {
        $this->baseDir = PathHelper::normalize(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_file_dir_bench_' . uniqid('', true));
        FlysystemHelper::createDirectory($this->baseDir);

        $logsDir = PathHelper::join($this->baseDir, 'logs');
        $exportsDir = PathHelper::join($this->baseDir, 'exports');
        FlysystemHelper::createDirectory($logsDir);
        FlysystemHelper::createDirectory($exportsDir);

        for ($i = 0; $i < 6; $i++) {
            FlysystemHelper::write(
                PathHelper::join($logsDir, sprintf('entry_%02d.log', $i)),
                'benchmark-log-' . $i,
            );
        }

        for ($i = 0; $i < 6; $i++) {
            FlysystemHelper::write(
                PathHelper::join($exportsDir, sprintf('report_%02d.txt', $i)),
                str_repeat('report-line-' . $i . PHP_EOL, 10),
            );
        }

        $this->filePath = PathHelper::join($this->baseDir, 'exports', 'report_00.txt');
        $this->expectedChecksum = hash('sha256', (string) FlysystemHelper::read($this->filePath));
    }

    public function tearDown(): void
    {
        if (FlysystemHelper::directoryExists($this->baseDir)) {
            FlysystemHelper::deleteDirectory($this->baseDir);
        }
    }

    public function benchDirectoryFindTxtFiles(): void
    {
        $directory = new DirectoryOperations($this->baseDir);
        $directory->find(['extension' => 'txt']);
    }

    public function benchDirectoryListContentsDetailed(): void
    {
        $directory = new DirectoryOperations($this->baseDir);
        $directory->listContents(true);
    }

    public function benchDirectorySize(): void
    {
        $directory = new DirectoryOperations($this->baseDir);
        $directory->size();
    }

    public function benchFileReadAndVerifyChecksum(): void
    {
        $file = new FileOperations($this->filePath);
        $file->read();
        $file->verifyChecksum($this->expectedChecksum, 'sha256');
    }
}
