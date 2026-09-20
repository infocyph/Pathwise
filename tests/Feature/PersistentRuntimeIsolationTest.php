<?php

declare(strict_types=1);

use Infocyph\Pathwise\Security\LocalPathContainment;
use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;
use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;
use Infocyph\Pathwise\Utils\PathHelper;

beforeEach(function (): void {
    $this->runtimeFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_runtime_' . bin2hex(random_bytes(8));
    $this->rootA = $this->runtimeFixture . DIRECTORY_SEPARATOR . 'root-a';
    $this->rootB = $this->runtimeFixture . DIRECTORY_SEPARATOR . 'root-b';
    $this->tempA = $this->runtimeFixture . DIRECTORY_SEPARATOR . 'temp-a';
    $this->tempB = $this->runtimeFixture . DIRECTORY_SEPARATOR . 'temp-b';

    foreach ([$this->rootA, $this->rootB, $this->tempA, $this->tempB] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create persistent-runtime fixture: {$directory}");
        }
    }
});

afterEach(function (): void {
    if (!is_dir($this->runtimeFixture)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->runtimeFixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($this->runtimeFixture);
});

test('high-cardinality normalization does not retain request paths in static state', function (): void {
    $staticProperties = array_values(array_filter(
        new ReflectionClass(PathHelper::class)->getProperties(),
        static fn(ReflectionProperty $property): bool => $property->isStatic(),
    ));

    expect($staticProperties)->toBe([]);

    $last = '';
    for ($index = 0; $index < 10_000; $index++) {
        $last = PathHelper::normalize("tenant/{$index}/../object-{$index}/file.txt");
    }

    expect($last)->toContain('object-9999')
        ->not->toContain('..');
});

test('interleaved upload processors keep roots policies and scanners isolated', function (): void {
    $sourceA = $this->runtimeFixture . DIRECTORY_SEPARATOR . 'source-a.txt';
    $sourceB = $this->runtimeFixture . DIRECTORY_SEPARATOR . 'source-b.csv';
    file_put_contents($sourceA, "alpha\n");
    file_put_contents($sourceB, "name,value\nbeta,2\n");

    $strict = new UploadProcessor();
    $strict->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
    $strict->setDirectorySettings($this->rootA, tempDir: $this->tempA);
    $strict->setExtensionPolicy(['txt']);
    $strict->setValidationSettings([], 1_048_576);
    $strict->setMalwareScanner(new class implements MalwareScannerInterface {
        public function scan(MalwareScanRequest $request): MalwareScanVerdict
        {
            return is_file($request->localPath)
                ? MalwareScanVerdict::CLEAN
                : MalwareScanVerdict::UNKNOWN;
        }
    });
    $strict->setMalwareScanMode(MalwareScanMode::REQUIRED);

    $standard = new UploadProcessor();
    $standard->setDirectorySettings($this->rootB, tempDir: $this->tempB);
    $standard->setExtensionPolicy(['csv']);
    $standard->setStrictContentTypeValidation(false);
    $standard->setValidationSettings([], 1_048_576);

    $publishedA = $strict->ingestSource(UploadSource::fromPath($sourceA, 'client-a.txt'));
    $publishedB = $standard->ingestSource(UploadSource::fromPath($sourceB, 'client-b.csv'));

    expect(LocalPathContainment::isSameOrDescendant($this->rootA, $publishedA))->toBeTrue()
        ->and(LocalPathContainment::isSameOrDescendant($this->rootB, $publishedB))->toBeTrue()
        ->and($strict->getTrustProfile())->toBe(UploadTrustProfile::UNTRUSTED_DATA)
        ->and($standard->getTrustProfile())->toBe(UploadTrustProfile::STANDARD)
        ->and($strict->getInfo()['maxChunkCount'])->toBeGreaterThan(0)
        ->and($strict->getInfo()['maxChunkSize'])->toBeGreaterThan(0)
        ->and($standard->getInfo()['maxChunkCount'])->toBe(0)
        ->and($standard->getInfo()['maxChunkSize'])->toBe(0)
        ->and($strict->getInfo()['malwareScanStatus'])->toBe('required_ready')
        ->and($standard->getInfo()['malwareScanStatus'])->toBe('unconfigured')
        ->and(array_values(array_diff(scandir($this->tempA) ?: [], ['.', '..'])))->toBe([])
        ->and(array_values(array_diff(scandir($this->tempB) ?: [], ['.', '..'])))->toBe([]);

    if (PHP_OS_FAMILY !== 'Windows') {
        $permissions = fileperms($publishedA);
        if (!is_int($permissions)) {
            throw new RuntimeException('Unable to read strict published-file permissions.');
        }

        expect($permissions & 0777)->toBe(0600);
    }
});
