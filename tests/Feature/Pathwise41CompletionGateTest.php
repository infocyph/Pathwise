<?php

declare(strict_types=1);

use Infocyph\Pathwise\Core\ExecutionStrategy;
use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
use Infocyph\Pathwise\Exceptions\NativeExecutionException;
use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use Infocyph\Pathwise\FileManager\FileCompression;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;

function pathwise41GateDirectory(string $name): string
{
    $directory = PathHelper::join(sys_get_temp_dir(), 'pathwise_41_gate_' . $name . '_' . bin2hex(random_bytes(8)));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create Pathwise 4.1 gate directory '{$directory}'.");
    }

    return $directory;
}

function pathwise41GateArchive(string $archivePath, array $entries): void
{
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Unable to create Pathwise 4.1 gate archive '{$archivePath}'.");
    }

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
}

beforeEach(function (): void {
    $this->gateRoot = pathwise41GateDirectory('root');
    $this->gateArchive = PathHelper::join($this->gateRoot, 'fixture.zip');
    $this->gateDestination = PathHelper::join($this->gateRoot, 'extract');
});

afterEach(function (): void {
    if (is_dir($this->gateRoot)) {
        FlysystemHelper::deleteDirectory($this->gateRoot);
    }
});

test('aggregate archive byte limit is enforced before publication', function (): void {
    pathwise41GateArchive($this->gateArchive, [
        'one.txt' => str_repeat('a', 8),
        'two.txt' => str_repeat('b', 8),
    ]);

    expect(fn () => (new FileCompression($this->gateArchive))
        ->setExtractionLimits(
            maxEntries: 10,
            maxEntryUncompressedBytes: 32,
            maxTotalUncompressedBytes: 10,
            maxCompressionRatio: 1_000.0,
        )
        ->decompress($this->gateDestination))
        ->toThrow(UnsafeArchiveEntryException::class)
        ->and(is_dir($this->gateDestination))->toBeFalse();
});

test('hardened file decompression refuses forced native unzip', function (): void {
    pathwise41GateArchive($this->gateArchive, ['safe.txt' => 'safe']);

    expect(fn () => (new FileCompression($this->gateArchive))
        ->setExecutionStrategy(ExecutionStrategy::NATIVE)
        ->decompress($this->gateDestination))
        ->toThrow(NativeExecutionException::class, 'hardened extraction requires Pathwise byte and rollback enforcement');
});

test('hardened directory unzip refuses forced native unzip', function (): void {
    pathwise41GateArchive($this->gateArchive, ['safe.txt' => 'safe']);

    expect(fn () => (new DirectoryOperations($this->gateDestination))
        ->setExecutionStrategy(ExecutionStrategy::NATIVE)
        ->unzip($this->gateArchive))
        ->toThrow(NativeExecutionException::class, 'hardened extraction requires Pathwise byte and rollback enforcement');
});

test('production dependency surface stays filesystem focused and runtime neutral', function (): void {
    $composerPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json';
    $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
    $requirements = array_keys($composer['require'] ?? []);

    expect($requirements)->not->toContain(
        'infocyph/runwire',
        'infocyph/webrick',
        'infocyph/foundation',
        'infocyph/intermix',
        'infocyph/reqshield',
        'ext-pcntl',
        'ext-posix',
    );
});

test('production source exposes no Runwire types', function (): void {
    $sourceRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($entry->getPathname());
        expect($source)->toBeString()
            ->and($source)->not->toContain('Runwire\\');
    }
});
