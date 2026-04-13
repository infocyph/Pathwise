<?php

namespace Infocyph\Pathwise\Storage;

use Infocyph\Pathwise\Utils\FlysystemHelper;
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
    /**
     * @var array<string, array{package: string, adapter_class: class-string}>
     */
    private const array OFFICIAL_DRIVERS = [
        'local' => [
            'package' => 'league/flysystem-local',
            'adapter_class' => LocalFilesystemAdapter::class,
        ],
        'ftp' => [
            'package' => 'league/flysystem-ftp',
            'adapter_class' => 'League\\Flysystem\\Ftp\\FtpAdapter',
        ],
        'inmemory' => [
            'package' => 'league/flysystem-memory',
            'adapter_class' => 'League\\Flysystem\\InMemory\\InMemoryFilesystemAdapter',
        ],
        'read-only' => [
            'package' => 'league/flysystem-read-only',
            'adapter_class' => 'League\\Flysystem\\ReadOnly\\ReadOnlyFilesystemAdapter',
        ],
        'path-prefixing' => [
            'package' => 'league/flysystem-path-prefixing',
            'adapter_class' => 'League\\Flysystem\\PathPrefixing\\PathPrefixedAdapter',
        ],
        'aws-s3' => [
            'package' => 'league/flysystem-aws-s3-v3',
            'adapter_class' => 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter',
        ],
        'async-aws-s3' => [
            'package' => 'league/flysystem-async-aws-s3',
            'adapter_class' => 'League\\Flysystem\\AsyncAwsS3\\AsyncAwsS3Adapter',
        ],
        'azure-blob-storage' => [
            'package' => 'league/flysystem-azure-blob-storage',
            'adapter_class' => 'League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter',
        ],
        'google-cloud-storage' => [
            'package' => 'league/flysystem-google-cloud-storage',
            'adapter_class' => 'League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter',
        ],
        'mongodb-gridfs' => [
            'package' => 'league/flysystem-gridfs',
            'adapter_class' => 'League\\Flysystem\\GridFS\\GridFSAdapter',
        ],
        'sftp-v2' => [
            'package' => 'league/flysystem-sftp-v2',
            'adapter_class' => 'League\\Flysystem\\PhpseclibV2\\SftpAdapter',
        ],
        'sftp-v3' => [
            'package' => 'league/flysystem-sftp-v3',
            'adapter_class' => 'League\\Flysystem\\PhpseclibV3\\SftpAdapter',
        ],
        'webdav' => [
            'package' => 'league/flysystem-webdav',
            'adapter_class' => 'League\\Flysystem\\WebDAV\\WebDAVAdapter',
        ],
        'ziparchive' => [
            'package' => 'league/flysystem-ziparchive',
            'adapter_class' => 'League\\Flysystem\\ZipArchive\\ZipArchiveAdapter',
        ],
    ];

    /** @var array<string, callable(array<string, mixed>): FilesystemOperator> */
    private static array $drivers = [];

    public static function clearDrivers(): void
    {
        self::$drivers = [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function createFilesystem(array $config): FilesystemOperator
    {
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

        $custom = self::createFromRegisteredDriver($driver, $config);
        if ($custom !== null) {
            return $custom;
        }

        if (self::isOfficialDriver($driver)) {
            return self::createOfficialFilesystem($driver, $config);
        }

        throw new \InvalidArgumentException(
            "Unsupported storage driver '{$driver}'. Register it via StorageFactory::registerDriver().",
        );
    }

    public static function driverNames(): array
    {
        return array_keys(self::$drivers);
    }

    public static function hasDriver(string $name): bool
    {
        return isset(self::$drivers[self::canonicalDriverName($name)]);
    }

    public static function isOfficialDriver(string $driver): bool
    {
        return isset(self::OFFICIAL_DRIVERS[self::canonicalDriverName($driver)]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function mount(string $name, array $config): FilesystemOperator
    {
        $filesystem = self::createFilesystem($config);
        FlysystemHelper::mount($name, $filesystem);

        return $filesystem;
    }

    /**
     * @param array<string, array<string, mixed>> $mounts
     */
    public static function mountMany(array $mounts): void
    {
        foreach ($mounts as $name => $config) {
            self::mount((string) $name, $config);
        }
    }

    /**
     * @return array<string, array{package: string, adapter_class: class-string}>
     */
    public static function officialDrivers(): array
    {
        return self::OFFICIAL_DRIVERS;
    }

    /**
     * @param callable(array<string, mixed>): FilesystemOperator $factory
     */
    public static function registerDriver(string $name, callable $factory): void
    {
        $driver = self::canonicalDriverName($name);
        if ($driver === '') {
            throw new \InvalidArgumentException('Driver name is required.');
        }

        self::$drivers[$driver] = $factory;
    }

    public static function suggestedPackage(string $driver): ?string
    {
        $normalized = self::canonicalDriverName($driver);

        return self::OFFICIAL_DRIVERS[$normalized]['package'] ?? null;
    }

    public static function unregisterDriver(string $name): void
    {
        unset(self::$drivers[self::canonicalDriverName($name)]);
    }

    private static function canonicalDriverName(string $name): string
    {
        $normalized = self::normalizeDriverName($name);

        return self::DRIVER_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createFromRegisteredDriver(string $driver, array $config): ?FilesystemOperator
    {
        if (!isset(self::$drivers[$driver])) {
            return null;
        }

        $filesystem = self::$drivers[$driver]($config);
        if (!$filesystem instanceof FilesystemOperator) {
            throw new \InvalidArgumentException("Driver '{$driver}' factory must return a FilesystemOperator.");
        }

        return $filesystem;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createLocalFilesystem(array $config): FilesystemOperator
    {
        $root = (string) ($config['root'] ?? '');
        if ($root === '') {
            throw new \InvalidArgumentException('Local driver requires a non-empty "root" path.');
        }

        return new Filesystem(new LocalFilesystemAdapter($root), self::resolveOptions($config));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createLocalFilesystemFromConfig(array $config): FilesystemOperator
    {
        $adapter = self::resolveAdapter($config);
        if ($adapter !== null) {
            return new Filesystem($adapter, self::resolveOptions($config));
        }

        return self::createLocalFilesystem($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function createOfficialFilesystem(string $driver, array $config): FilesystemOperator
    {
        $driver = self::canonicalDriverName($driver);
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
            /** @var FilesystemAdapter $adapter */
            $adapter = new $adapterClass();

            return new Filesystem($adapter, self::resolveOptions($config));
        }

        $constructor = $config['constructor'] ?? null;
        if (!is_array($constructor)) {
            throw new \InvalidArgumentException(
                "Storage driver '{$driver}' requires either 'adapter' or 'constructor' config.",
            );
        }

        $arguments = array_is_list($constructor) ? $constructor : array_values($constructor);
        $adapter = new $adapterClass(...$arguments);

        return new Filesystem($adapter, self::resolveOptions($config));
    }

    private static function normalizeDriverName(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveAdapter(array $config): ?FilesystemAdapter
    {
        if (!array_key_exists('adapter', $config)) {
            return null;
        }

        $adapter = $config['adapter'];
        if (!$adapter instanceof FilesystemAdapter) {
            throw new \InvalidArgumentException('The "adapter" config value must implement FilesystemAdapter.');
        }

        return $adapter;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveDriver(array $config): string
    {
        $driver = self::canonicalDriverName((string) ($config['driver'] ?? 'local'));
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

        return $options;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveProvidedFilesystem(array $config): ?FilesystemOperator
    {
        if (!array_key_exists('filesystem', $config)) {
            return null;
        }

        $filesystem = $config['filesystem'];
        if (!$filesystem instanceof FilesystemOperator) {
            throw new \InvalidArgumentException('The "filesystem" config value must implement FilesystemOperator.');
        }

        return $filesystem;
    }
}
