<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Native;

final class NativeCommandRunner
{
    /** @var array<string, bool> */
    private static array $executableCache = [];

    public static function commandExists(string $command): bool
    {
        $cacheKey = PHP_OS_FAMILY . ':' . strtolower($command);
        if (array_key_exists($cacheKey, self::$executableCache)) {
            return self::$executableCache[$cacheKey];
        }

        return self::$executableCache[$cacheKey] = self::locateExecutable($command);
    }

    /**
     * @param list<string> $command
     * @return array{success: bool, output: list<string>, code: int}
     */
    public static function run(array $command, ?string $workingDirectory = null): array
    {
        if ($command === []) {
            return ['success' => false, 'output' => ['No command was provided.'], 'code' => 127];
        }

        $pipes = [];
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return ['success' => false, 'output' => ['Unable to start native command.'], 'code' => 127];
        }

        $stdout = is_resource($pipes[1] ?? null) ? stream_get_contents($pipes[1]) : '';
        $stderr = is_resource($pipes[2] ?? null) ? stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exitCode = proc_close($process);
        $combined = trim((is_string($stdout) ? $stdout : '') . "\n" . (is_string($stderr) ? $stderr : ''));
        $output = $combined === '' ? [] : preg_split('/\R/', $combined);

        return [
            'success' => $exitCode === 0,
            'output' => is_array($output) ? $output : [],
            'code' => $exitCode,
        ];
    }

    /** @return \Generator<int, string> */
    private static function executableCandidates(string $command, string $path): \Generator
    {
        $extensions = PHP_OS_FAMILY === 'Windows' ? self::windowsExecutableExtensions() : [''];
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                yield rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $command . $extension;
            }
        }
    }

    private static function locateExecutable(string $command): bool
    {
        if ($command === '' || str_contains($command, "\0")) {
            return false;
        }
        if (str_contains($command, '/') || str_contains($command, '\\')) {
            return is_file($command) && (PHP_OS_FAMILY === 'Windows' || is_executable($command));
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return false;
        }
        foreach (self::executableCandidates($command, $path) as $candidate) {
            if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function windowsExecutableExtensions(): array
    {
        $pathExtensions = getenv('PATHEXT');
        if (!is_string($pathExtensions) || $pathExtensions === '') {
            return ['.exe', '.com', '.bat', '.cmd'];
        }

        return array_values(array_filter(array_map(strtolower(...), explode(PATH_SEPARATOR, $pathExtensions))));
    }
}
