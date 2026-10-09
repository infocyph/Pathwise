<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\FileSizeExceededException;
use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

function uploadSourceTempDirectory(string $prefix): string
{
    $directory = PathHelper::join(sys_get_temp_dir(), $prefix . bin2hex(random_bytes(8)));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory '{$directory}'.");
    }

    return $directory;
}

function uploadSourcePathExists(?string $path): bool
{
    return is_string($path) && (is_file($path) || is_link($path));
}

beforeEach(function (): void {
    FlysystemHelper::reset();
    $this->uploadDir = uploadSourceTempDirectory('pathwise_upload_source_dest_');
    $this->stagingDir = uploadSourceTempDirectory('pathwise_upload_source_stage_');
    $this->sourceDir = uploadSourceTempDirectory('pathwise_upload_source_input_');
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

test('it ingests framework mover sources and cleans Pathwise staging', function (): void {
    $stagedPath = null;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$stagedPath): void {
            $stagedPath = $target;
            file_put_contents($target, 'framework-content');
        },
        clientFilename: 'report.txt',
        size: 1,
        clientMediaType: 'application/not-trusted',
    );

    $destination = $this->processor->ingestSource($source);
    $remaining = glob($this->stagingDir . DIRECTORY_SEPARATOR . 'pathwise-upload-*');

    expect(file_get_contents($destination))->toBe('framework-content')
        ->and(is_string($stagedPath))->toBeTrue()
        ->and(uploadSourcePathExists($stagedPath))->toBeFalse()
        ->and($remaining === false ? [] : $remaining)->toBe([]);
});

test('materialized upload state uses private permissions', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(true)->toBeTrue();

        return;
    }

    $source = UploadSource::fromMover(
        static function (string $target): void {
            file_put_contents($target, 'private-content');
        },
        clientFilename: 'private.txt',
    );
    $materialized = $source->materialize($this->stagingDir);

    try {
        $directoryMode = fileperms(dirname($materialized->path));
        $fileMode = fileperms($materialized->path);

        expect($directoryMode)->toBeInt()
            ->and($directoryMode & 0777)->toBe(0700)
            ->and($fileMode)->toBeInt()
            ->and($fileMode & 0777)->toBe(0600);
    } finally {
        $materialized->cleanup();
    }
});

test('it cleans materialized sources when upload validation fails', function (): void {
    $stagedPath = null;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$stagedPath): void {
            $stagedPath = $target;
            file_put_contents($target, '<?php echo 1;');
        },
        clientFilename: 'payload.php',
    );

    expect(fn () => $this->processor->ingestSource($source))
        ->toThrow(UploadException::class, 'Blocked file extension')
        ->and(is_string($stagedPath))->toBeTrue()
        ->and(uploadSourcePathExists($stagedPath))->toBeFalse();
});

test('it rejects source upload errors before invoking a framework mover', function (): void {
    $called = false;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$called): void {
            $called = true;
            file_put_contents($target, 'should-not-run');
        },
        clientFilename: 'missing.txt',
        error: UPLOAD_ERR_NO_FILE,
    );

    expect(fn () => $this->processor->ingestSource($source))
        ->toThrow(UploadException::class, 'No file sent')
        ->and($called)->toBeFalse();
});

test('it validates the actual materialized size instead of trusting source metadata', function (): void {
    $this->processor->setValidationSettings(['text/plain'], 5);
    $stagedPath = null;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$stagedPath): void {
            $stagedPath = $target;
            file_put_contents($target, '1234567890');
        },
        clientFilename: 'oversized.txt',
        size: 1,
    );

    expect(fn () => $this->processor->ingestSource($source))
        ->toThrow(FileSizeExceededException::class, 'Exceeded file size limit')
        ->and(is_string($stagedPath))->toBeTrue()
        ->and(uploadSourcePathExists($stagedPath))->toBeFalse();
});

test('borrowed paths remain and owned paths are consumed after staging', function (): void {
    $borrowed = PathHelper::join($this->sourceDir, 'borrowed.txt');
    file_put_contents($borrowed, 'borrowed-content');

    $borrowedDestination = $this->processor->ingestSource(
        UploadSource::fromPath($borrowed, owned: false),
    );

    expect(is_file($borrowed))->toBeTrue()
        ->and(file_get_contents($borrowedDestination))->toBe('borrowed-content');

    $owned = PathHelper::join($this->sourceDir, 'owned.php');
    file_put_contents($owned, '<?php echo 1;');

    expect(fn () => $this->processor->ingestSource(
        UploadSource::fromPath($owned, owned: true),
    ))->toThrow(UploadException::class, 'Blocked file extension')
        ->and(is_file($owned))->toBeFalse();
});

test('caller owned streams stay open and are read from their current position', function (): void {
    $stream = tmpfile();
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to create test stream.');
    }

    fwrite($stream, 'prefix-body');
    fseek($stream, 7);

    $destination = $this->processor->ingestSource(
        UploadSource::fromStream($stream, 'stream.txt'),
    );

    expect(file_get_contents($destination))->toBe('body')
        ->and(is_resource($stream))->toBeTrue();

    fclose($stream);
});

test('it fails cleanly when a caller closes a stream before materialization', function (): void {
    $stream = tmpfile();
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to create test stream.');
    }

    $source = UploadSource::fromStream($stream, 'closed.txt');
    fclose($stream);

    expect(fn () => $this->processor->ingestSource($source))
        ->toThrow(UploadException::class, 'no longer readable');

    $remaining = glob($this->stagingDir . DIRECTORY_SEPARATOR . 'pathwise-upload-*');
    expect($remaining === false ? [] : $remaining)->toBe([]);
});

test('typed sources work for resumable chunks and staging is cleaned', function (): void {
    $stagedPath = null;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$stagedPath): void {
            $stagedPath = $target;
            file_put_contents($target, 'chunk-body');
        },
        clientFilename: 'chunk.txt',
    );

    $state = $this->processor->processChunkUploadSource(
        source: $source,
        uploadId: 'typed_source_chunk',
        chunkIndex: 0,
        totalChunks: 1,
        originalFilename: 'merged.txt',
    );
    $destination = $this->processor->finalizeChunkUpload('typed_source_chunk');

    expect($state->complete)->toBeTrue()
        ->and($state->receivedChunks)->toBe(1)
        ->and(file_get_contents($destination))->toBe('chunk-body')
        ->and(is_string($stagedPath))->toBeTrue()
        ->and(uploadSourcePathExists($stagedPath))->toBeFalse();
});

test('materialization failure removes a partially written staging file', function (): void {
    $stagedPath = null;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$stagedPath): void {
            $stagedPath = $target;
            file_put_contents($target, 'partial');
            throw new RuntimeException('mover failed');
        },
        clientFilename: 'partial.txt',
    );

    expect(fn () => $source->materialize($this->stagingDir))
        ->toThrow(UploadException::class, 'Unable to materialize upload source')
        ->and(is_string($stagedPath))->toBeTrue()
        ->and(uploadSourcePathExists($stagedPath))->toBeFalse();

    $remaining = glob($this->stagingDir . DIRECTORY_SEPARATOR . 'pathwise-upload-*');
    expect($remaining === false ? [] : $remaining)->toBe([]);
});

test('bounded stream staging reads no more than limit plus one byte from caller streams', function (?int $declaredSize): void {
    $this->processor->setValidationSettings(['text/plain'], 16);
    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to allocate stream source.');
    }
    fwrite($stream, str_repeat('x', 1024 * 1024));
    rewind($stream);

    try {
        $source = UploadSource::fromStream($stream, 'bounded.txt', size: $declaredSize);
        expect(fn () => $this->processor->ingestSource($source))
            ->toThrow(FileSizeExceededException::class, 'Exceeded file size limit')
            ->and(ftell($stream))->toBe(17)
            ->and(is_resource($stream))->toBeTrue()
            ->and(glob($this->stagingDir . DIRECTORY_SEPARATOR . 'pathwise-upload-*'))->toBe([]);
    } finally {
        fclose($stream);
    }
})->with(['understated size' => 1, 'unknown size' => null]);

test('bounded materialization permits exactly the configured byte ceiling', function (): void {
    $this->processor->setValidationSettings(['text/plain'], 4);
    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to allocate stream source.');
    }
    fwrite($stream, 'four');
    rewind($stream);

    try {
        $destination = $this->processor->ingestSource(UploadSource::fromStream($stream, 'exact.txt'));
        expect(file_get_contents($destination))->toBe('four')
            ->and(is_resource($stream))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

test('bounded path staging retains an owned source when limit is exceeded', function (): void {
    $this->processor->setValidationSettings(['text/plain'], 4);
    $path = PathHelper::join($this->sourceDir, 'owned-bounded.txt');
    file_put_contents($path, 'five!');

    expect(fn () => $this->processor->ingestSource(
        UploadSource::fromPath($path, clientFilename: 'owned-bounded.txt', size: 1, owned: true),
    ))->toThrow(FileSizeExceededException::class, 'Exceeded file size limit')
        ->and(file_get_contents($path))->toBe('five!')
        ->and(glob($this->stagingDir . DIRECTORY_SEPARATOR . 'pathwise-upload-*'))->toBe([]);
});

test('strict untrusted upload limit cannot be disabled before invoking a mover', function (): void {
    $this->processor->setTrustProfile(\Infocyph\Pathwise\StreamHandler\UploadTrustProfile::UNTRUSTED_DATA);
    $this->processor->setValidationSettings(['text/plain'], 0);
    $called = false;
    $source = UploadSource::fromMover(
        static function (string $target) use (&$called): void {
            $called = true;
            file_put_contents($target, 'should-not-be-staged');
        },
        clientFilename: 'strict.txt',
    );

    expect(fn () => $this->processor->ingestSource($source))
        ->toThrow(UploadException::class, 'finite total file size limit')
        ->and($called)->toBeFalse();
});
