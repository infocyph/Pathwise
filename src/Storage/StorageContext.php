<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Storage;

use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\FilesystemOperator;

/**
 * Instance-scoped filesystem registry and resolver.
 *
 * The context keeps named filesystems and custom driver factories isolated from
 * Pathwise's static convenience registries so multiple applications/generations
 * can safely reuse the same logical storage names in one process.
 */
final class StorageContext
{
    /** @var array<string, array<string, mixed>> */
    private readonly array $configurations;

    private readonly string $defaultFilesystem;

    /** @var array<string, callable(array<string, mixed>): mixed> */
    private readonly array $drivers;

    /** @var array<string, FilesystemOperator> */
    private array $filesystems = [];

    /**
     * @param array<array-key, mixed> $configurations
     * @param array<array-key, mixed> $drivers Custom driver factories returning FilesystemOperator instances.
     */
    public function __construct(array $configurations, string $defaultFilesystem, array $drivers = [])
    {
        if ($configurations === []) {
            throw new \InvalidArgumentException('At least one filesystem configuration is required.');
        }

        $normalizedConfigurations = [];
        foreach ($configurations as $name => $configuration) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException(
                    'Filesystem configurations must map valid names to configuration arrays.',
                );
            }

            $normalizedName = self::normalizeName($name);
            if (isset($normalizedConfigurations[$normalizedName])) {
                throw new \InvalidArgumentException(
                    "Filesystem '{$normalizedName}' is configured more than once.",
                );
            }

            $normalizedConfigurations[$normalizedName] = self::normalizeConfiguration($configuration);
        }

        $defaultFilesystem = self::normalizeName($defaultFilesystem);
        if (!isset($normalizedConfigurations[$defaultFilesystem])) {
            throw new \InvalidArgumentException(
                "Default filesystem '{$defaultFilesystem}' is not configured.",
            );
        }

        $this->configurations = $normalizedConfigurations;
        $this->defaultFilesystem = $defaultFilesystem;
        $this->drivers = $this->normalizeDrivers($drivers);
    }

    /** @return array<string, mixed> */
    public function configuration(?string $name = null): array
    {
        return $this->configurations[$this->resolveName($name)];
    }

    public function defaultFilesystem(): string
    {
        return $this->defaultFilesystem;
    }

    public function filesystem(?string $name = null): FilesystemOperator
    {
        $resolved = $this->resolveName($name);

        return $this->filesystems[$resolved] ??= $this->createFilesystem($this->configurations[$resolved]);
    }

    /** @return list<string> */
    public function filesystemNames(): array
    {
        return array_keys($this->configurations);
    }

    public function hasDriver(string $name): bool
    {
        return isset($this->drivers[self::normalizeName($name)]);
    }

    public function hasFilesystem(string $name): bool
    {
        return isset($this->configurations[self::normalizeName($name)]);
    }

    public function isLocal(?string $name = null): bool
    {
        $configuration = $this->configuration($name);
        $driver = $configuration['driver'] ?? 'local';

        return is_string($driver)
            && strtolower(trim($driver)) === 'local'
            && is_string($configuration['root'] ?? null)
            && $configuration['root'] !== '';
    }

    public function localPath(string $path = '', ?string $name = null): string
    {
        [$resolvedName, $location] = $this->resolveIdentity($path, $name);
        $configuration = $this->configurations[$resolvedName];
        $driver = $configuration['driver'] ?? 'local';
        $root = $configuration['root'] ?? null;

        if (!is_string($driver) || strtolower(trim($driver)) !== 'local' || !is_string($root) || $root === '') {
            throw new \InvalidArgumentException(
                "Filesystem '{$resolvedName}' does not expose a local root path.",
            );
        }

        $root = PathHelper::normalize($root);

        return $location === '' ? $root : PathHelper::join($root, $location);
    }

    /**
     * Return a canonical context path without registering a process-global mount.
     */
    public function path(string $path = '', ?string $name = null): string
    {
        [$resolvedName, $location] = $this->resolveIdentity($path, $name);

        return $location === '' ? $resolvedName . '://' : $resolvedName . '://' . $location;
    }

    /**
     * Resolve a logical path to its filesystem operator and adapter-relative location.
     *
     * @return array{FilesystemOperator, string}
     */
    public function resolve(string $path = '', ?string $name = null): array
    {
        [$resolvedName, $location] = $this->resolveIdentity($path, $name);

        return [$this->filesystem($resolvedName), $location];
    }

    private static function isAbsoluteLogicalPath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[a-zA-Z]:/', $path) === 1;
    }

    /** @return array<string, mixed> */
    private static function normalizeConfiguration(mixed $configuration): array
    {
        if (!is_array($configuration)) {
            throw new \InvalidArgumentException(
                'Filesystem configurations must map valid names to configuration arrays.',
            );
        }

        $normalized = [];
        foreach ($configuration as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Filesystem configuration keys must be strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private static function normalizeLocation(string $location): string
    {
        $normalized = str_replace('\\', '/', trim($location));
        if (str_contains($normalized, "\0")) {
            throw new \InvalidArgumentException('Storage path cannot contain a null byte.');
        }
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $normalized) === 1) {
            throw new \InvalidArgumentException('Storage path cannot contain parent-directory traversal.');
        }

        $segments = array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn(string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        return implode('/', $segments);
    }

    private static function normalizeName(string $name): string
    {
        $normalized = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9._-]*$/D', $normalized) !== 1) {
            throw new \InvalidArgumentException("Invalid storage name '{$name}'.");
        }

        return $normalized;
    }

    /** @param array<string, mixed> $configuration */
    private function createFilesystem(array $configuration): FilesystemOperator
    {
        $driver = $configuration['driver'] ?? null;
        if (is_string($driver)) {
            $normalizedDriver = self::normalizeName($driver);
            if (isset($this->drivers[$normalizedDriver])) {
                $filesystem = ($this->drivers[$normalizedDriver])($configuration);
                if (!$filesystem instanceof FilesystemOperator) {
                    throw new \UnexpectedValueException(
                        "Storage driver '{$normalizedDriver}' must return a FilesystemOperator.",
                    );
                }

                return $filesystem;
            }

            if (!StorageFactory::isOfficialDriver($normalizedDriver)) {
                throw new \InvalidArgumentException(
                    "Unsupported context storage driver '{$normalizedDriver}'. Supply it to StorageContext explicitly.",
                );
            }
        }

        return StorageFactory::createFilesystem($configuration);
    }

    /**
     * @param array<array-key, mixed> $drivers
     * @return array<string, callable(array<string, mixed>): mixed>
     */
    private function normalizeDrivers(array $drivers): array
    {
        $normalized = [];
        foreach ($drivers as $name => $factory) {
            if (!is_string($name) || !is_callable($factory)) {
                throw new \InvalidArgumentException(
                    'Storage drivers must map valid names to callable factories.',
                );
            }

            $driver = self::normalizeName($name);
            if (StorageFactory::isOfficialDriver($driver)) {
                throw new \InvalidArgumentException(
                    "Storage driver name '{$name}' is reserved by an official driver.",
                );
            }
            if (isset($normalized[$driver])) {
                throw new \InvalidArgumentException(
                    "Storage driver '{$driver}' is configured more than once.",
                );
            }

            $normalized[$driver] = $factory;
        }

        return $normalized;
    }

    /** @return array{string, string} */
    private function resolveIdentity(string $path, ?string $name): array
    {
        $normalizedPath = str_replace('\\', '/', trim($path));
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9._-]*):\/\/(.*)$/s', $normalizedPath, $matches) === 1) {
            $scheme = self::normalizeName($matches[1]);
            if (!isset($this->configurations[$scheme])) {
                throw new \InvalidArgumentException("Filesystem '{$scheme}' is not configured.");
            }

            if ($name !== null && $this->resolveName($name) !== $scheme) {
                throw new \InvalidArgumentException(
                    "Filesystem path '{$scheme}://' conflicts with explicitly selected filesystem '{$name}'.",
                );
            }

            return [$scheme, self::normalizeLocation($matches[2])];
        }

        if ($normalizedPath !== '' && self::isAbsoluteLogicalPath($normalizedPath)) {
            throw new \InvalidArgumentException(
                'StorageContext paths must be relative or use a configured filesystem scheme.',
            );
        }

        return [$this->resolveName($name), self::normalizeLocation($normalizedPath)];
    }

    private function resolveName(?string $name): string
    {
        $resolved = $name === null || trim($name) === ''
            ? $this->defaultFilesystem
            : self::normalizeName($name);

        if (!isset($this->configurations[$resolved])) {
            throw new \InvalidArgumentException("Filesystem '{$resolved}' is not configured.");
        }

        return $resolved;
    }
}
