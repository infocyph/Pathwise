<?php

declare(strict_types=1);

use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

function storageContextTempDirectory(string $prefix): string
{
    $directory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . $prefix
        . bin2hex(random_bytes(8));

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory '{$directory}'.");
    }

    return $directory;
}

beforeEach(function (): void {
    FlysystemHelper::reset();
    StorageFactory::clearDrivers();
});

afterEach(function (): void {
    FlysystemHelper::reset();
    StorageFactory::clearDrivers();
});

test('it resolves and caches named filesystems without global mounts', function (): void {
    $root = storageContextTempDirectory('pathwise_context_');

    try {
        $context = new StorageContext([
            'local' => ['driver' => 'local', 'root' => $root],
        ], 'local');

        [$filesystem, $location] = $context->resolve('nested/file.txt');
        $filesystem->write($location, 'context-data');

        expect($context->filesystem())->toBe($filesystem)
            ->and($context->filesystem('LOCAL'))->toBe($filesystem)
            ->and($filesystem->read('nested/file.txt'))->toBe('context-data')
            ->and($context->path('nested/file.txt'))->toBe('local://nested/file.txt')
            ->and($context->localPath('nested/file.txt'))->toBe(PathHelper::join($root, 'nested/file.txt'))
            ->and($context->isLocal())->toBeTrue()
            ->and(FlysystemHelper::hasMount('local'))->toBeFalse();
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('two contexts can reuse the same logical filesystem name without cross talk', function (): void {
    $rootA = storageContextTempDirectory('pathwise_context_a_');
    $rootB = storageContextTempDirectory('pathwise_context_b_');

    try {
        $contextA = new StorageContext([
            'assets' => ['driver' => 'local', 'root' => $rootA],
        ], 'assets');
        $contextB = new StorageContext([
            'assets' => ['driver' => 'local', 'root' => $rootB],
        ], 'assets');

        [$filesystemA, $locationA] = $contextA->resolve('assets://same.txt');
        [$filesystemB, $locationB] = $contextB->resolve('assets://same.txt');
        $filesystemA->write($locationA, 'A');
        $filesystemB->write($locationB, 'B');

        expect($filesystemA)->not->toBe($filesystemB)
            ->and($filesystemA->read('same.txt'))->toBe('A')
            ->and($filesystemB->read('same.txt'))->toBe('B')
            ->and(file_get_contents(PathHelper::join($rootA, 'same.txt')))->toBe('A')
            ->and(file_get_contents(PathHelper::join($rootB, 'same.txt')))->toBe('B')
            ->and(FlysystemHelper::hasMount('assets'))->toBeFalse();
    } finally {
        FlysystemHelper::deleteDirectory($rootA);
        FlysystemHelper::deleteDirectory($rootB);
    }
});

test('custom drivers are isolated per context and do not mutate StorageFactory', function (): void {
    $rootA = storageContextTempDirectory('pathwise_driver_a_');
    $rootB = storageContextTempDirectory('pathwise_driver_b_');

    $factory = static fn (string $root): Closure => static function (array $configuration) use ($root): FilesystemOperator {
        unset($configuration);

        return new Filesystem(new LocalFilesystemAdapter($root));
    };

    try {
        $contextA = new StorageContext(
            ['tenant' => ['driver' => 'isolated']],
            'tenant',
            ['isolated' => $factory($rootA)],
        );
        $contextB = new StorageContext(
            ['tenant' => ['driver' => 'isolated']],
            'tenant',
            ['isolated' => $factory($rootB)],
        );

        $contextA->filesystem()->write('value.txt', 'A');
        $contextB->filesystem()->write('value.txt', 'B');

        expect($contextA->hasDriver('isolated'))->toBeTrue()
            ->and($contextB->hasDriver('isolated'))->toBeTrue()
            ->and(StorageFactory::hasDriver('isolated'))->toBeFalse()
            ->and($contextA->filesystem()->read('value.txt'))->toBe('A')
            ->and($contextB->filesystem()->read('value.txt'))->toBe('B');
    } finally {
        FlysystemHelper::deleteDirectory($rootA);
        FlysystemHelper::deleteDirectory($rootB);
    }
});

test('a context cannot fall through to globally registered custom drivers', function (): void {
    $root = storageContextTempDirectory('pathwise_global_driver_');

    StorageFactory::registerDriver(
        'global-only',
        static fn (array $configuration): FilesystemOperator => new Filesystem(
            new LocalFilesystemAdapter(is_string($configuration['root'] ?? null) ? $configuration['root'] : $root),
        ),
    );

    try {
        $context = new StorageContext([
            'tenant' => ['driver' => 'global-only', 'root' => $root],
        ], 'tenant');

        expect(fn () => $context->filesystem())
            ->toThrow(InvalidArgumentException::class, 'Supply it to StorageContext explicitly');
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('context drivers must return filesystem operators', function (): void {
    $context = new StorageContext(
        ['tenant' => ['driver' => 'broken']],
        'tenant',
        ['broken' => static function (array $configuration): object {
            unset($configuration);

            return new stdClass();
        }],
    );

    expect(fn () => $context->filesystem())
        ->toThrow(UnexpectedValueException::class, 'must return a FilesystemOperator');
});

test('it resolves explicit schemes and rejects conflicting selection', function (): void {
    $rootA = storageContextTempDirectory('pathwise_scheme_a_');
    $rootB = storageContextTempDirectory('pathwise_scheme_b_');

    try {
        $context = new StorageContext([
            'primary' => ['driver' => 'local', 'root' => $rootA],
            'archive' => ['driver' => 'local', 'root' => $rootB],
        ], 'primary');

        [$archive, $location] = $context->resolve('archive://reports/q1.txt');

        expect($location)->toBe('reports/q1.txt')
            ->and($archive)->toBe($context->filesystem('archive'))
            ->and($context->filesystemNames())->toBe(['primary', 'archive'])
            ->and($context->defaultFilesystem())->toBe('primary')
            ->and(fn () => $context->resolve('archive://q1.txt', 'primary'))
            ->toThrow(InvalidArgumentException::class, 'conflicts');
    } finally {
        FlysystemHelper::deleteDirectory($rootA);
        FlysystemHelper::deleteDirectory($rootB);
    }
});

test('it rejects invalid topology and unsafe logical paths', function (): void {
    $root = storageContextTempDirectory('pathwise_context_invalid_');

    try {
        $context = new StorageContext([
            'local' => ['driver' => 'local', 'root' => $root],
        ], 'local');

        expect(fn () => new StorageContext([], 'local'))
            ->toThrow(InvalidArgumentException::class, 'At least one filesystem')
            ->and(fn () => new StorageContext(['local' => ['driver' => 'local', 'root' => $root]], 'missing'))
            ->toThrow(InvalidArgumentException::class, 'Default filesystem')
            ->and(fn () => new StorageContext(['local' => [0 => 'invalid']], 'local'))
            ->toThrow(InvalidArgumentException::class, 'configuration keys must be strings')
            ->and(fn () => new StorageContext(
                ['local' => ['driver' => 'local', 'root' => $root]],
                'local',
                ['s3' => static fn (array $config): FilesystemOperator => StorageFactory::createFilesystem($config)],
            ))
            ->toThrow(InvalidArgumentException::class, 'reserved')
            ->and(fn () => $context->resolve('../outside.txt'))
            ->toThrow(InvalidArgumentException::class, 'parent-directory traversal')
            ->and(fn () => $context->localPath('local://safe/../../outside.txt'))
            ->toThrow(InvalidArgumentException::class, 'parent-directory traversal');
    } finally {
        FlysystemHelper::deleteDirectory($root);
    }
});

test('it rejects absolute logical paths consistently across platforms', function (string $path): void {
    $context = new StorageContext([
        'local' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
    ], 'local');

    expect(fn () => $context->resolve($path))
        ->toThrow(InvalidArgumentException::class, 'must be relative');
})->with([
    'unix root' => '/outside.txt',
    'windows drive absolute slash' => 'C:/outside.txt',
    'windows drive absolute backslash' => 'C:\\outside.txt',
    'windows drive relative' => 'C:outside.txt',
    'UNC path' => '\\\\server\\share.txt',
]);
