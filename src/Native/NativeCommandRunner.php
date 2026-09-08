<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Native;

use Infocyph\Pathwise\Results\NativeExecutionResult;

final class NativeCommandRunner
{
    private const int EXIT_IO_ERROR = 126;
    private const int EXIT_OUTPUT_LIMIT = 125;
    private const int EXIT_START_FAILED = 127;
    private const int EXIT_TIMEOUT = 124;
    private const int READ_CHUNK_BYTES = 65_536;

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
     */
    public static function run(
        array $command,
        ?string $workingDirectory = null,
        ?NativeExecutionLimits $limits = null,
    ): NativeExecutionResult {
        $limits ??= new NativeExecutionLimits();
        if (!self::isValidCommand($command)) {
            return new NativeExecutionResult(
                false,
                '',
                self::EXIT_START_FAILED,
                ['No valid command was provided.'],
                NativeExecutionFailure::START_FAILED,
            );
        }

        $displayCommand = self::displayCommand($command);
        $pipes = [];
        $process = self::startProcess($command, $workingDirectory, $pipes);
        if (!is_resource($process)) {
            return new NativeExecutionResult(
                false,
                $displayCommand,
                self::EXIT_START_FAILED,
                ['Unable to start native command.'],
                NativeExecutionFailure::START_FAILED,
            );
        }

        self::closePipe($pipes[0] ?? null);
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($stdout) || !is_resource($stderr)) {
            self::closePipe($stdout);
            self::closePipe($stderr);
            self::terminateImmediately($process);
            proc_close($process);

            return new NativeExecutionResult(
                false,
                $displayCommand,
                self::EXIT_START_FAILED,
                ['Unable to initialize native command output pipes.'],
                NativeExecutionFailure::START_FAILED,
            );
        }

        if (!stream_set_blocking($stdout, false) || !stream_set_blocking($stderr, false)) {
            self::closePipe($stdout);
            self::closePipe($stderr);
            self::terminateImmediately($process);
            proc_close($process);

            return new NativeExecutionResult(
                false,
                $displayCommand,
                self::EXIT_START_FAILED,
                ['Unable to configure native command output pipes.'],
                NativeExecutionFailure::START_FAILED,
            );
        }

        $stdoutBuffer = '';
        $stderrBuffer = '';
        $stdoutBytes = 0;
        $stderrBytes = 0;
        $failure = null;
        $statusExitCode = null;
        $deadline = self::deadlineFromNow($limits->timeoutSeconds);

        while (true) {
            $failure ??= self::drainPipe(
                $stdout,
                $stdoutBuffer,
                $stdoutBytes,
                $limits->stdoutBytes,
                NativeExecutionFailure::STDOUT_LIMIT,
            );
            $failure ??= self::drainPipe(
                $stderr,
                $stderrBuffer,
                $stderrBytes,
                $limits->stderrBytes,
                NativeExecutionFailure::STDERR_LIMIT,
            );

            $status = proc_get_status($process);
            if (!is_array($status)) {
                $failure ??= NativeExecutionFailure::IO_ERROR;
                $statusExitCode = self::terminateBounded($process, $stdout, $stderr, $limits);

                break;
            }

            if (!$status['running']) {
                $statusExitCode = is_int($status['exitcode']) ? $status['exitcode'] : null;
                $failure ??= self::drainPipe(
                    $stdout,
                    $stdoutBuffer,
                    $stdoutBytes,
                    $limits->stdoutBytes,
                    NativeExecutionFailure::STDOUT_LIMIT,
                );
                $failure ??= self::drainPipe(
                    $stderr,
                    $stderrBuffer,
                    $stderrBytes,
                    $limits->stderrBytes,
                    NativeExecutionFailure::STDERR_LIMIT,
                );

                break;
            }

            if ($failure !== null) {
                $statusExitCode = self::terminateBounded($process, $stdout, $stderr, $limits);

                break;
            }

            if (hrtime(true) >= $deadline) {
                $failure = NativeExecutionFailure::TIMEOUT;
                $statusExitCode = self::terminateBounded($process, $stdout, $stderr, $limits);

                break;
            }

            usleep($limits->pollIntervalMicroseconds);
        }

        self::closePipe($stdout);
        self::closePipe($stderr);
        $closeExitCode = proc_close($process);
        $exitCode = self::resolveExitCode($failure, $statusExitCode, $closeExitCode);
        if ($failure === null && $exitCode !== 0) {
            $failure = NativeExecutionFailure::EXIT_CODE;
        }

        $stdoutLines = self::lines($stdoutBuffer);
        $stderrLines = self::lines($stderrBuffer);

        return new NativeExecutionResult(
            $failure === null && $exitCode === 0,
            $displayCommand,
            $exitCode,
            [...$stdoutLines, ...$stderrLines],
            $failure,
            $stdoutLines,
            $stderrLines,
        );
    }

    private static function deadlineFromNow(float $seconds): int
    {
        return hrtime(true) + (int) round($seconds * 1_000_000_000);
    }

    /**
     * @param resource $pipe
     */
    private static function discardAvailable(mixed $pipe): void
    {
        while (is_resource($pipe) && !feof($pipe)) {
            $chunk = fread($pipe, self::READ_CHUNK_BYTES);
            if (!is_string($chunk) || $chunk === '') {
                return;
            }
        }
    }

    /**
     * @param list<string> $command
     */
    private static function displayCommand(array $command): string
    {
        return implode(' ', array_map(
            static fn(string $argument): string => json_encode($argument, JSON_UNESCAPED_SLASHES) ?: '""',
            $command,
        ));
    }

    /**
     * @param resource $pipe
     */
    private static function drainPipe(
        mixed $pipe,
        string &$buffer,
        int &$bytes,
        int $limit,
        NativeExecutionFailure $limitFailure,
    ): ?NativeExecutionFailure {
        while (is_resource($pipe) && !feof($pipe)) {
            $remaining = $limit - $bytes;
            $readLength = min(self::READ_CHUNK_BYTES, max(1, $remaining + 1));
            $chunk = fread($pipe, $readLength);
            if ($chunk === false) {
                $metadata = stream_get_meta_data($pipe);
                if (($metadata['blocked'] ?? true) === false && !feof($pipe)) {
                    return null;
                }

                return NativeExecutionFailure::IO_ERROR;
            }
            if ($chunk === '') {
                return null;
            }

            $length = strlen($chunk);
            if ($length > $remaining) {
                if ($remaining > 0) {
                    $buffer .= substr($chunk, 0, $remaining);
                    $bytes += $remaining;
                }

                return $limitFailure;
            }

            $buffer .= $chunk;
            $bytes += $length;
        }

        return null;
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

    /**
     * @param list<string> $command
     */
    private static function isValidCommand(array $command): bool
    {
        if ($command === [] || !array_is_list($command)) {
            return false;
        }

        foreach ($command as $index => $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                return false;
            }
            if ($index === 0 && $argument === '') {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function lines(string $buffer): array
    {
        $normalized = rtrim($buffer, "\r\n");
        if ($normalized === '') {
            return [];
        }

        $lines = preg_split('/\R/', $normalized);

        return is_array($lines) ? array_values($lines) : [];
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

    private static function resolveExitCode(
        ?NativeExecutionFailure $failure,
        ?int $statusExitCode,
        int $closeExitCode,
    ): int {
        return match ($failure) {
            NativeExecutionFailure::TIMEOUT => self::EXIT_TIMEOUT,
            NativeExecutionFailure::STDOUT_LIMIT, NativeExecutionFailure::STDERR_LIMIT => self::EXIT_OUTPUT_LIMIT,
            NativeExecutionFailure::IO_ERROR => self::EXIT_IO_ERROR,
            NativeExecutionFailure::START_FAILED, NativeExecutionFailure::UNSUPPORTED => self::EXIT_START_FAILED,
            default => is_int($statusExitCode) && $statusExitCode >= 0 ? $statusExitCode : $closeExitCode,
        };
    }

    private static function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param list<string> $command
     * @param array<int, resource> $pipes
     * @return resource|false
     */
    private static function startProcess(array $command, ?string $workingDirectory, array &$pipes): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            try {
                return proc_open(
                    $command,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    $workingDirectory,
                    null,
                    ['bypass_shell' => true],
                );
            } catch (\Throwable) {
                return false;
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param resource $process
     */
    private static function terminateBounded(
        mixed $process,
        mixed $stdout,
        mixed $stderr,
        NativeExecutionLimits $limits,
    ): ?int {
        self::runSilently(static fn(): bool => proc_terminate($process));
        $exitCode = self::waitForExit($process, $stdout, $stderr, $limits);
        if ($exitCode !== null) {
            return $exitCode;
        }

        self::runSilently(static fn(): bool => proc_terminate($process, 9));

        return self::waitForExit($process, $stdout, $stderr, $limits);
    }

    /**
     * @param resource $process
     */
    private static function terminateImmediately(mixed $process): void
    {
        self::runSilently(static fn(): bool => proc_terminate($process));
        $status = proc_get_status($process);
        if (is_array($status) && $status['running']) {
            self::runSilently(static fn(): bool => proc_terminate($process, 9));
        }
    }

    /**
     * @param resource $process
     */
    private static function waitForExit(
        mixed $process,
        mixed $stdout,
        mixed $stderr,
        NativeExecutionLimits $limits,
    ): ?int {
        $deadline = self::deadlineFromNow($limits->terminationGraceSeconds);

        do {
            self::discardAvailable($stdout);
            self::discardAvailable($stderr);
            $status = proc_get_status($process);
            if (is_array($status) && !$status['running']) {
                return is_int($status['exitcode']) ? $status['exitcode'] : null;
            }
            if (!is_array($status)) {
                return null;
            }

            usleep($limits->pollIntervalMicroseconds);
        } while (hrtime(true) < $deadline);

        return null;
    }

    /**
     * @param resource|null $pipe
     */
    private static function closePipe(mixed $pipe): void
    {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
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
