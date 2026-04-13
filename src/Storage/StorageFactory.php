<?php

namespace Infocyph\Pathwise\Storage;

use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

final class StorageFactory
{
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
        if (isset($config['filesystem'])) {
            $filesystem = $config['filesystem'];
            if (!$filesystem instanceof FilesystemOperator) {
                throw new \InvalidArgumentException('The "filesystem" config value must implement FilesystemOperator.');
            }

            return $filesystem;
        }

        if (isset($config['adapter'])) {
            $adapter = $config['adapter'];
            if (!$adapter instanceof FilesystemAdapter) {
                throw new \InvalidArgumentException('The "adapter" config value must implement FilesystemAdapter.');
            }

            return new Filesystem($adapter, self::resolveOptions($config));
        }

        $driver = self::resolveDriver($config);

        if ($driver === 'local') {
            return self::createLocalFilesystem($config);
        }

        if (!isset(self::$drivers[$driver])) {
            throw new \InvalidArgumentException(
                "Unsupported storage driver '{$driver}'. Register it via StorageFactory::registerDriver().",
            );
        }

        $filesystem = self::$drivers[$driver]($config);

        if (!$filesystem instanceof FilesystemOperator) {
            throw new \InvalidArgumentException("Driver '{$driver}' factory must return a FilesystemOperator.");
        }

        return $filesystem;
    }

    public static function driverNames(): array
    {
        return array_keys(self::$drivers);
    }

    public static function hasDriver(string $name): bool
    {
        return isset(self::$drivers[self::normalizeDriverName($name)]);
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
     * @param callable(array<string, mixed>): FilesystemOperator $factory
     */
    public static function registerDriver(string $name, callable $factory): void
    {
        $driver = self::normalizeDriverName($name);
        if ($driver === '') {
            throw new \InvalidArgumentException('Driver name is required.');
        }

        self::$drivers[$driver] = $factory;
    }

    public static function unregisterDriver(string $name): void
    {
        unset(self::$drivers[self::normalizeDriverName($name)]);
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

    private static function normalizeDriverName(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveDriver(array $config): string
    {
        $driver = self::normalizeDriverName((string) ($config['driver'] ?? 'local'));
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
}
