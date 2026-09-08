<?php

declare(strict_types=1);

use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

function processorContextTempDirectory(string $prefix): string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory '{$directory}'.");
    }

    return $directory;
}

beforeEach(function (): void {
    FlysystemHelper::reset();
});

afterEach(function (): void {
    FlysystemHelper::reset();
});

test('upload processors use isolated storage contexts without global mounts', function (): void {
    $rootA = processorContextTempDirectory('pathwise_processor_a_');
    $rootB = processorContextTempDirectory('pathwise_processor_b_');
    $sourceA = tempnam(sys_get_temp_dir(), 'pathwise_source_a_');
    $sourceB = tempnam(sys_get_temp_dir(), 'pathwise_source_b_');

    if (!is_string($sourceA) || !is_string($sourceB)) {
        throw new RuntimeException('Unable to create upload source fixtures.');
    }

    file_put_contents($sourceA, 'context-a');
    file_put_contents($sourceB, 'context-b');

    try {
        $contextA = new StorageContext(['files' => ['driver' => 'local', 'root' => $rootA]], 'files');
        $contextB = new StorageContext(['files' => ['driver' => 'local', 'root' => $rootB]], 'files');

        $uploaderA = new UploadProcessor();
        $uploaderA->setStorageContext($contextA);
        $uploaderA->setDirectorySettings('files://uploads');
        $pathA = $uploaderA->ingestSource(UploadSource::fromPath($sourceA, 'report.txt'));

        $uploaderB = new UploadProcessor();
        $uploaderB->setStorageContext($contextB);
        $uploaderB->setDirectorySettings('files://uploads');
        $pathB = $uploaderB->ingestSource(UploadSource::fromPath($sourceB, 'report.txt'));

        expect($pathA)->toStartWith('files://uploads/')
            ->and($pathB)->toStartWith('files://uploads/')
            ->and($contextA->filesystem()->read(substr($pathA, strlen('files://'))))->toBe('context-a')
            ->and($contextB->filesystem()->read(substr($pathB, strlen('files://'))))->toBe('context-b')
            ->and(FlysystemHelper::hasMount('files'))->toBeFalse();
    } finally {
        if (is_file($sourceA)) {
            unlink($sourceA);
        }
        if (is_file($sourceB)) {
            unlink($sourceB);
        }
        if (is_dir($rootA)) {
            FlysystemHelper::deleteDirectory($rootA);
        }
        if (is_dir($rootB)) {
            FlysystemHelper::deleteDirectory($rootB);
        }
    }
});

test('download processors stream through isolated storage contexts without global mounts', function (): void {
    $rootA = processorContextTempDirectory('pathwise_download_a_');
    $rootB = processorContextTempDirectory('pathwise_download_b_');

    try {
        $contextA = new StorageContext(['files' => ['driver' => 'local', 'root' => $rootA]], 'files');
        $contextB = new StorageContext(['files' => ['driver' => 'local', 'root' => $rootB]], 'files');
        $contextA->filesystem()->write('downloads/report.txt', 'context-a');
        $contextB->filesystem()->write('downloads/report.txt', 'context-b');

        $downloadsA = new DownloadProcessor();
        $downloadsA->setStorageContext($contextA);
        $downloadsA->setAllowedRoots(['files://downloads']);
        $preparationA = $downloadsA->prepareDownload('files://downloads/report.txt');

        $downloadsB = new DownloadProcessor();
        $downloadsB->setStorageContext($contextB);
        $downloadsB->setAllowedRoots(['files://downloads']);
        $preparationB = $downloadsB->prepareDownload('files://downloads/report.txt');

        expect(implode('', iterator_to_array($downloadsA->streamChunks($preparationA))))->toBe('context-a')
            ->and(implode('', iterator_to_array($downloadsB->streamChunks($preparationB))))->toBe('context-b')
            ->and($contextA->localPath('downloads/report.txt'))->toBe(PathHelper::join($rootA, 'downloads/report.txt'))
            ->and($contextB->localPath('downloads/report.txt'))->toBe(PathHelper::join($rootB, 'downloads/report.txt'))
            ->and(FlysystemHelper::hasMount('files'))->toBeFalse();
    } finally {
        if (is_dir($rootA)) {
            FlysystemHelper::deleteDirectory($rootA);
        }
        if (is_dir($rootB)) {
            FlysystemHelper::deleteDirectory($rootB);
        }
    }
});
