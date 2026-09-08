<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\FileManager\SafeFileWriter;
use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Pathwise\Native\NativeCommandRunner;
use Infocyph\Pathwise\Native\NativeExecutionLimits;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;
use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PhpBench\Attributes as Bench;
use ZipArchive;

/**
 * Pathwise 4 release workloads that exercise the boundaries introduced by the
 * major release. Fixtures are created outside measured subjects.
 */
#[Bench\Iterations(1)]
#[Bench\Revs(1)]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
final class Pathwise4ReleaseBench
{
    private string $adapterRoot;

    private string $archivePath;

    private string $baseDirectory;

    private string $dedupDirectory;

    private string $indexDirectory;

    private string $largeFile;

    private string $payloadFile;

    private StorageContext $storageContext;

    private string $uploadDirectory;

    private string $uploadTempDirectory;

    public function benchAdapterStagedWriter(): void
    {
        $writer = new SafeFileWriter('staged-writer.bin');
        $writer->writeBinary(str_repeat('r', 1_048_576));
        $writer->close();
    }

    public function benchArchiveValidationAndExtraction(): void
    {
        $destination = PathHelper::join($this->baseDirectory, 'extract-' . bin2hex(random_bytes(4)));
        $archive = new FileCompression($this->archivePath);
        $archive->setExtractionLimits(
            maxEntries: 500,
            maxEntryUncompressedBytes: 1_048_576,
            maxTotalUncompressedBytes: 16_777_216,
            maxCompressionRatio: 1_000.0,
        );
        $archive->decompress($destination);
    }

    public function benchAtomicLocalWriter(): void
    {
        $writer = new SafeFileWriter(PathHelper::join($this->baseDirectory, 'atomic-writer.bin'));
        $writer->enableAtomicWrite();
        $writer->writeBinary(str_repeat('l', 1_048_576));
        $writer->close();
    }

    public function benchChecksumIteration500Files(): void
    {
        $count = 0;
        foreach (ChecksumIndexer::iterate($this->indexDirectory) as $_entry) {
            $count++;
        }

        if ($count !== 500) {
            throw new \RuntimeException("Release checksum benchmark expected 500 entries, got {$count}.");
        }
    }

    public function benchDownloadRangeIteration(): void
    {
        $download = new DownloadProcessor();
        $download->setChunkSize(65_536);
        $preparation = $download->prepareDownload(
            $this->largeFile,
            rangeHeader: 'bytes=1048576-2097151',
        );

        $bytes = 0;
        foreach ($download->streamChunks($preparation) as $chunk) {
            $bytes += strlen($chunk);
        }

        if ($bytes !== 1_048_576) {
            throw new \RuntimeException("Release download benchmark streamed {$bytes} bytes.");
        }
    }

    public function benchHardLinkDeduplication200Files(): void
    {
        $result = ChecksumIndexer::deduplicateWithHardLinks($this->dedupDirectory);
        if (count($result->linked) !== 100) {
            throw new \RuntimeException('Release deduplication benchmark did not link every duplicate pair.');
        }
    }

    public function benchMalwareStagingWithoutExternalEngine(): void
    {
        $uploader = new UploadProcessor();
        $uploader->setDirectorySettings($this->uploadDirectory, false, $this->uploadTempDirectory);
        $uploader->setExtensionPolicy(['txt']);
        $uploader->setStrictContentTypeValidation(false);
        $uploader->setValidationSettings([], 4_194_304);
        $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);
        $uploader->setMalwareScanner(new class implements MalwareScannerInterface {
            public function scan(MalwareScanRequest $request): MalwareScanVerdict
            {
                if (!is_file($request->localPath)) {
                    throw new \RuntimeException('Release benchmark scanner did not receive a local file.');
                }

                return MalwareScanVerdict::CLEAN;
            }
        });

        $uploader->ingestSource(UploadSource::fromPath(
            $this->payloadFile,
            'payload.txt',
            filesize($this->payloadFile) ?: null,
            'text/plain',
        ));
    }

    public function benchNativeRunnerBoundedOverhead(): void
    {
        if (!NativeCommandRunner::supportsBoundedExecution()) {
            return;
        }

        $result = NativeCommandRunner::run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "ok");'],
            limits: new NativeExecutionLimits(
                timeoutSeconds: 5.0,
                stdoutBytes: 65_536,
                stderrBytes: 65_536,
                terminationGraceSeconds: 0.25,
                pollIntervalMicroseconds: 1_000,
            ),
        );

        if (!$result->success) {
            throw new \RuntimeException('Release native-runner benchmark failed.');
        }
    }

    public function benchQueueReserveReleaseRenewAcknowledge(): void
    {
        $queue = new FileJobQueue(PathHelper::join($this->baseDirectory, 'lease-queue.json'));
        $queue->enqueue('release-benchmark', ['value' => 'x'], priority: 10);

        $reservation = $queue->reserve();
        if ($reservation === null) {
            throw new \RuntimeException('Release queue benchmark could not reserve its job.');
        }

        $queue->release($reservation);
        $reservation = $queue->reserve();
        if ($reservation === null) {
            throw new \RuntimeException('Release queue benchmark could not re-reserve its job.');
        }

        $reservation = $queue->renew($reservation);
        $queue->acknowledge($reservation);
    }

    public function benchStorageContextHotResolve(): void
    {
        for ($index = 0; $index < 1_000; $index++) {
            [$filesystem, $location] = $this->storageContext->resolve('bench://nested/file.txt');
            if ($location !== 'nested/file.txt' || $filesystem !== $this->storageContext->filesystem('bench')) {
                throw new \RuntimeException('StorageContext hot resolution returned an unexpected result.');
            }
        }
    }

    public function benchUploadStreamMaterialization(): void
    {
        $stream = fopen($this->payloadFile, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to open release upload fixture.');
        }

        try {
            $source = UploadSource::fromStream(
                $stream,
                'payload.txt',
                filesize($this->payloadFile) ?: null,
                'text/plain',
            );
            $materialization = $source->materialize($this->uploadTempDirectory);
            try {
                if ($materialization->size !== filesize($this->payloadFile)) {
                    throw new \RuntimeException('Upload materialization size mismatch.');
                }
            } finally {
                $materialization->cleanup();
            }
        } finally {
            fclose($stream);
        }
    }

    public function setUp(): void
    {
        FlysystemHelper::reset();

        $this->baseDirectory = PathHelper::join(
            sys_get_temp_dir(),
            'pathwise4_release_bench_' . bin2hex(random_bytes(8)),
        );
        $this->adapterRoot = PathHelper::join($this->baseDirectory, 'adapter');
        $contextRoot = PathHelper::join($this->baseDirectory, 'context');
        $this->dedupDirectory = PathHelper::join($this->baseDirectory, 'dedup');
        $this->indexDirectory = PathHelper::join($this->baseDirectory, 'index');
        $this->uploadDirectory = PathHelper::join($this->baseDirectory, 'uploads');
        $this->uploadTempDirectory = PathHelper::join($this->baseDirectory, 'upload-temp');

        foreach ([
            $this->baseDirectory,
            $this->adapterRoot,
            $contextRoot,
            $this->dedupDirectory,
            $this->indexDirectory,
            $this->uploadDirectory,
            $this->uploadTempDirectory,
        ] as $directory) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException("Unable to create release benchmark fixture: {$directory}");
            }
        }

        $this->payloadFile = PathHelper::join($this->baseDirectory, 'payload.txt');
        $this->largeFile = PathHelper::join($this->baseDirectory, 'large.bin');
        $this->archivePath = PathHelper::join($this->baseDirectory, 'fixture.zip');

        file_put_contents($this->payloadFile, str_repeat('pathwise-release\n', 65_536));
        file_put_contents($this->largeFile, str_repeat('0123456789abcdef', 524_288));

        $this->createIndexFixture();
        $this->createDedupFixture();
        $this->createArchiveFixture();

        $this->storageContext = new StorageContext([
            'bench' => [
                'driver' => 'local',
                'root' => $contextRoot,
            ],
        ], 'bench');
        $this->storageContext->filesystem();

        FlysystemHelper::setDefaultFilesystem(
            new Filesystem(new LocalFilesystemAdapter($this->adapterRoot)),
        );
    }

    public function tearDown(): void
    {
        FlysystemHelper::reset();
        $this->deleteLocalTree($this->baseDirectory);
    }

    private function createArchiveFixture(): void
    {
        $archive = new ZipArchive();
        if ($archive->open($this->archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create release archive fixture.');
        }

        try {
            for ($index = 0; $index < 250; $index++) {
                if (!$archive->addFromString(
                    sprintf('folder/entry-%04d.txt', $index),
                    str_repeat((string) ($index % 10), 1_024),
                )) {
                    throw new \RuntimeException('Unable to add release archive fixture entry.');
                }
            }
        } finally {
            $archive->close();
        }
    }

    private function createDedupFixture(): void
    {
        for ($index = 0; $index < 100; $index++) {
            $content = 'duplicate-group-' . $index . '-' . str_repeat((string) ($index % 10), 1_024);
            file_put_contents(PathHelper::join($this->dedupDirectory, sprintf('a-%03d.bin', $index)), $content);
            file_put_contents(PathHelper::join($this->dedupDirectory, sprintf('b-%03d.bin', $index)), $content);
        }
    }

    private function createIndexFixture(): void
    {
        for ($index = 0; $index < 500; $index++) {
            file_put_contents(
                PathHelper::join($this->indexDirectory, sprintf('entry-%04d.txt', $index)),
                str_repeat((string) ($index % 10), 1_024),
            );
        }
    }

    private function deleteLocalTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
