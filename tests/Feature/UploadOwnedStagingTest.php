<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;
use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

function ownedStagingTempDirectory(string $prefix): string
{
    $directory = PathHelper::join(sys_get_temp_dir(), $prefix . bin2hex(random_bytes(8)));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory '{$directory}'.");
    }

    return $directory;
}

beforeEach(function (): void {
    FlysystemHelper::reset();
    $this->uploadDir = ownedStagingTempDirectory('pathwise_owned_upload_dest_');
    $this->stagingDir = ownedStagingTempDirectory('pathwise_owned_upload_stage_');
    $this->sourceDir = ownedStagingTempDirectory('pathwise_owned_upload_source_');
    $this->processor = new UploadProcessor();
    $this->processor->setDirectorySettings($this->uploadDir, false, $this->stagingDir);
    $this->processor->setValidationSettings(['text/plain'], 1024 * 1024);
});

afterEach(function (): void {
    foreach ([$this->uploadDir, $this->stagingDir, $this->sourceDir] as $directory) {
        if (is_dir($directory)) {
            FlysystemHelper::deleteDirectory($directory);
        }
    }
    FlysystemHelper::reset();
});

test('trusted ingest validates a private copy and preserves the source on failure', function (): void {
    $source = PathHelper::join($this->sourceDir, 'payload.php');
    file_put_contents($source, '<?php echo 1;');

    expect(fn () => $this->processor->ingestFile([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($source),
        'tmp_name' => $source,
        'name' => 'payload.php',
    ]))->toThrow(UploadException::class, 'Blocked file extension')
        ->and(is_file($source))->toBeTrue()
        ->and(file_get_contents($source))->toBe('<?php echo 1;');
});

test('trusted ingest consumes the source only after staged content is persisted', function (): void {
    $source = PathHelper::join($this->sourceDir, 'note.txt');
    file_put_contents($source, 'owned-stage-content');

    $destination = $this->processor->ingestFile([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($source),
        'tmp_name' => $source,
        'name' => 'note.txt',
    ]);

    expect(is_file($source))->toBeFalse()
        ->and(file_get_contents($destination))->toBe('owned-stage-content');
});

test('source mutation during malware scanning cannot change the published staged artifact', function (): void {
    $source = PathHelper::join($this->sourceDir, 'scan.txt');
    file_put_contents($source, 'original-content');

    $this->processor->setMalwareScanner(new class($source) implements MalwareScannerInterface {
        public function __construct(private readonly string $sourcePath)
        {
        }

        public function scan(MalwareScanRequest $request): MalwareScanVerdict
        {
            unset($request);
            file_put_contents($this->sourcePath, 'mutated-content!');

            return MalwareScanVerdict::CLEAN;
        }
    });

    $destination = $this->processor->ingestFile([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($source),
        'tmp_name' => $source,
        'name' => 'scan.txt',
    ]);

    expect(file_get_contents($destination))->toBe('original-content')
        ->and(is_file($source))->toBeFalse();
});

test('materialized upload identity rejects same-size staged mutation', function (): void {
    $source = UploadSource::fromMover(
        static function (string $target): void {
            file_put_contents($target, 'first-content');
        },
        clientFilename: 'identity.txt',
    );
    $materialization = $source->materialize($this->stagingDir);

    try {
        file_put_contents($materialization->path, 'other-content');

        expect(fn () => $materialization->assertUnchanged())
            ->toThrow(UploadException::class, 'Materialized upload changed before publication');
    } finally {
        $materialization->cleanup();
    }
});
