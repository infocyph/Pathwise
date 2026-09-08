<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

final class StorageFactory
{
    /** @var array<string, string> */
    private const array DRIVER_ALIASES = [
        'aws' => 'aws-s3',
        's3' => 'aws-s3',
        'asyncaws-s3' => 'async-aws-s3',
        'in-memory' => 'inmemory',
        'memory' => 'inmemory',
        'readonly' => 'read-only',
        'path-prefix' => 'path-prefixing',
        'pathprefixed' => 'path-prefixing',
        'azure' => 'azure-blob-storage',
        'gcs' => 'google-cloud-storage',
        'gridfs' => 'mongodb-gridfs',
        'sftp2' => 'sftp-v2',
        'sftp3' => 'sftp-v3',
        'zip' => 'ziparchive',
        'zip-archive' => 'ziparchive',
    ];

    /** @var array<string, array{package: string, adapter_class: non-empty-string}> */
    private const array OFFICIAL_DRIVERS = [
        'local' => ['package' => 'league/flysystem-local', 'adapter_class' => LocalFilesystemAdapter::class],
        'ftp' => ['package' => 'league/flysystem-ftp', 'adapter_class' => 'League\\Flysystem\\Ftp\\FtpAdapter'],
        'inmemory' => ['package' => 'league/flysystem-memory', 'adapter_class' => 'League\\Flysystem\\InMemory\\InMemoryFilesystemAdapter'],
        'read-only' => ['package' => 'league/flysystem-read-only', 'adapter_class' => 'League\\Flysystem\\ReadOnly\\ReadOnlyFilesystemAdapter'],
        'path-prefixing' => ['package' => 'league/flysystem-path-prefixing', 'adapter_class' => 'League\\Flysystem\\PathPrefixing\\PathPrefixedAdapter'],
        'aws-s3' => ['package' => 'league/flysystem-aws-s3-v3', 'adapter_class' => 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter'],
        'async-aws-s3' => ['package' => 'league/flysystem-async-aws-s3', 'adapter_class' => 'League\\Flysystem\\AsyncAwsS3\\AsyncAwsS3Adapter'],
        'azure-blob-storage' => ['package' => 'league/flysystem-azure-blob-storage', 'adapter_class' => 'League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter'],
        'google-cloud-storage' => ['package' => 'league/flysystem-google-cloud-storage', 'adapter_class' => 'League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter'],
        'mongodb-gridfs' => ['package' => 'league/flysystem-gridfs', 'adapter_class' => 'League\\Flysystem\\GridFS\\GridFSAdapter'],
        'sftp-v2' => ['package' => 'league/flysystem-sftp-v2', 'adapter_class' => 'League\\Flysystem\\PhpseclibV2\\SftpAdapter'],
        'sftp-v3' => ['package' => 'league/flysystem-sftp-v3', 'adapter_class' => 'League\\Flysystem\\PhpseclibV3\\SftpAdapter'],
        'webdav' => ['package' => 'league/flysystem-webdav', 'adapter_class' => 'League\\Flysystem\\WebDAV\\WebDAVAdapter'],
        'ziparchive' => ['package' => 'league/flysystem-ziparchive', 'adapter_class' => 'League\\Flysystem\\ZipArchive\\ZipArchiveAdapter'],
    ];

    /** @param array<string, mixed> $config */
    public static function createFilesystem(array $config): FilesystemOperator
    {
        self::assertUnambiguousConfig($config);
        $provided = self::resolveProvidedFilesystem($config);
        if ($provided !== null) {
            return $provided;
        }

        if (!array_key_exists('driver', $config)) {
            $adapter = self::resolveAdapter($config);
            if ($adapter !== null) {
                return new Filesystem($adapter, self::resolveOptions($config));
            }
        }

        $driver = self::resolveDriver($config);
        if ($driver === 'local') {
            return self::createLocalFilesystemFromConfig($config);
        }
        if (self::isOfficialDriver($driver)) {
            return self::createOfficialFilesystem($driver, $config);
        }

        throw new \InvalidArgumentException(
            "Unsupported storage driver '{$driver}'. Supply custom driver factories to StorageContext.",
        );
    }

    public static function isOfficialDriver(string $driver): bool
    {
        return isset(self::OFFICIAL_DRIVERS[self::canonicalDriverName($driver)]);
    }

    /** @return array<string, array{package: string, adapter_class: non-empty-string}> */
    public static function officialDrivers(): array
    {
        return self::OFFICIAL_DRIVERS;
    }

    public static function suggestedPackage(string $driver): ?string
    {
        $normalized = self::canonicalDriverName($driver);

        return self::OFFICIAL_DRIVERS[$normalized]['package'] ?? null;
    }

    /** @param array<string, mixed> $config */
    private static function assertUnambiguousConfig(array $config): void
    {
        $hasFilesystem = array_key_exists('filesystem', $config);
        $hasAdapter = array_key_exists('adapter', $config);
        $hasDriver = array_key_exists('driver', $config)
            || array_key_exists('root', $config)
            || array_key_exists('constructor', $config);
        if (array_sum([(int) $hasFilesystem, (int) $hasAdapter, (int) $hasDriver]) > 1) {
            throw new \InvalidArgumentException(
                'Storage configuration must use exactly one of filesystem, adapter, or driver mode.',
            );
        }
        if (array_key_exists('constructor', $config) && !array_key_exists('driver', $config)) {
            throw new \InvalidArgumentException('Storage "constructor" requires an explicit driver.');
        }
    }

    private static function canonicalDriverName(string $name): string
    {
        $normalized = strtolower(trim($name));

        return self::DRIVER_ALIASES[$normalized] ?? $normalized;
    }

    /** @param array<string, mixed> $config */
    private static function createLocalFilesystem(array $config): FilesystemOperator
    {
        $root = $config['root'] ?? null;
        if (!is_string($root) || $root === '') {
            throw new \InvalidArgumentException('Local driver requires a non-empty "root" path.');
        }

        return new Filesystem(new LocalFilesystemAdapter($root), self::resolveOptions($config));
    }

    /** @param array<string, mixed> $config */
    private static function createLocalFilesystemFromConfig(array $config): FilesystemOperator
    {
        $adapter = self::resolveAdapter($config);
        if ($adapter !== null) {
            return new Filesystem($adapter, self::resolveOptions($config));
        }

        return self::createLocalFilesystem($config);
    }

    /** @param array<string, mixed> $config */
    private static function createOfficialFilesystem(string $driver, array $config): FilesystemOperator
    {
        $driver = self::canonicalDriverName($driver);
        if ($driver === 'local') {
            return self::createLocalFilesystem($config);
        }

        $metadata = self::OFFICIAL_DRIVERS[$driver] ?? null;
        if ($metadata === null) {
            throw new \InvalidArgumentException("Unsupported official storage driver '{$driver}'.");
        }

        $adapter = self::resolveAdapter($config);
        if ($adapter !== null) {
            return new Filesystem($adapter, self::resolveOptions($config));
        }

        $adapterClass = $metadata['adapter_class'];
        if (!class_exists($adapterClass)) {
            throw new \InvalidArgumentException(
                "Storage driver '{$driver}' requires package '{$metadata['package']}'. "
                . "Install it and provide either 'adapter' or 'constructor' config.",
            );
        }
        if ($driver === 'inmemory') {
            $reflection = new \ReflectionClass($adapterClass);
            $required = $reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0;
            if ($required > 0) {
                throw new \InvalidArgumentException("Storage driver '{$driver}' requires explicit constructor config.");
            }

            /** @var FilesystemAdapter $adapter */
            $adapter = $reflection->newInstance();

            return new Filesystem($adapter, self::resolveOptions($config));
        }

        $constructor = $config['constructor'] ?? null;
        if (!is_array($constructor)) {
            throw new \InvalidArgumentException(
                "Storage driver '{$driver}' requires either 'adapter' or 'constructor' config.",
            );
        }
        if (!array_is_list($constructor)) {
            throw new \InvalidArgumentException('Storage "constructor" must be a list of positional arguments.');
        }

        /** @var FilesystemAdapter $adapter */
        $adapter = new \ReflectionClass($adapterClass)->newInstanceArgs($constructor);

        return new Filesystem($adapter, self::resolveOptions($config));
    }

    /** @param array<string, mixed> $config */
    private static function resolveAdapter(array $config): ?FilesystemAdapter
    {
        /** @var FilesystemAdapter|null $adapter */
        $adapter = self::resolveTypedConfigObject($config, 'adapter', FilesystemAdapter::class);

        return $adapter;
    }

    /** @param array<string, mixed> $config */
    private static function resolveDriver(array $config): string
    {
        $driverInput = $config['driver'] ?? 'local';
        if (!is_string($driverInput)) {
            throw new \InvalidArgumentException('Storage "driver" must be a non-empty string.');
        }

        $driver = self::canonicalDriverName($driverInput);
        if ($driver === '') {
            throw new \InvalidArgumentException('Storage "driver" must be a non-empty string.');
        }

        return $driver;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function resolveOptions(array $config): array
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            throw new \InvalidArgumentException('Storage "options" must be an array.');
        }

        $normalized = [];
        foreach ($options as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Storage "options" keys must be strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $config */
    private static function resolveProvidedFilesystem(array $config): ?FilesystemOperator
    {
        /** @var FilesystemOperator|null $filesystem */
        $filesystem = self::resolveTypedConfigObject($config, 'filesystem', FilesystemOperator::class);

        return $filesystem;
    }

    /**
     * @template T of object
     * @param array<string, mixed> $config
     * @param class-string<T> $expectedClass
     * @return T|null
     */
    private static function resolveTypedConfigObject(array $config, string $key, string $expectedClass): ?object
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (!$value instanceof $expectedClass) {
            throw new \InvalidArgumentException(
                sprintf('The "%s" config value must implement %s.', $key, $expectedClass),
            );
        }

        return $value;
    }
}
