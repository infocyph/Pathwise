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
        if (!self::supportsBoundedExecution()) {
            return false;
        }

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
        if (!self::supportsBoundedExecution()) {
            return self::unsupportedResult();
        }

        $limits ??= new NativeExecutionLimits();
        if (!self::isValidCommand($command)) {
            return self::startFailedResult('', 'No valid command was provided.');
        }

        $displayCommand = self::displayCommand($command);
        $pipes = [];
        $process = self::startProcess($command, $workingDirectory, $pipes);
        if (!is_resource($process)) {
            return self::startFailedResult($displayCommand, 'Unable to start native command.');
        }

        self::closePipe($pipes[0] ?? null);
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($stdout) || !is_resource($stderr)) {
            self::closePipe($stdout);
            self::closePipe($stderr);
            self::terminateImmediately($process);
            proc_close($process);

            return self::startFailedResult($displayCommand, 'Unable to initialize native command output pipes.');
        }

        if (!stream_set_blocking($stdout, false) || !stream_set_blocking($stderr, false)) {
            self::closePipe($stdout);
            self::closePipe($stderr);
            self::terminateImmediately($process);
            proc_close($process);

            return self::startFailedResult($displayCommand, 'Unable to configure bounded native command output pipes.');
        }

        $stdoutBuffer = '';
        $stderrBuffer = '';
        [$failure, $statusExitCode] = self::monitorProcess(
            $process,
            $stdout,
            $stderr,
            $limits,
            $stdoutBuffer,
            $stderrBuffer,
        );

        self::closePipe($stdout);
        self::closePipe($stderr);
        $closeExitCode = proc_close($process);

        return self::buildResult(
            $displayCommand,
            $failure,
            $statusExitCode,
            $closeExitCode,
            $stdoutBuffer,
            $stderrBuffer,
        );
    }

    public static function supportsBoundedExecution(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }

    private static function buildResult(
        string $displayCommand,
        ?NativeExecutionFailure $failure,
        int $statusExitCode,
        int $closeExitCode,
        string $stdoutBuffer,
        string $stderrBuffer,
    ): NativeExecutionResult {
        $exitCode = self::resolveExitCode($failure, $statusExitCode, $closeExitCode);
        if ($failure === null && $exitCode !== 0) {
            $failure = NativeExecutionFailure::EXIT_CODE;
        }

        $stdout = self::lines($stdoutBuffer);
        $stderr = self::lines($stderrBuffer);

        return new NativeExecutionResult(
            $failure === null,
            $displayCommand,
            $exitCode,
            [...$stdout, ...$stderr],
            $failure,
            $stdout,
            $stderr,
        );
    }

    private static function closePipe(mixed $pipe): void
    {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    private static function deadlineFromNow(float $seconds): int
    {
        return hrtime(true) + (int) round($seconds * 1_000_000_000);
    }

    private static function discardAvailable(mixed $pipe): void
    {
        while (is_resource($pipe) && !feof($pipe)) {
            $chunk = fread($pipe, self::READ_CHUNK_BYTES);
            if (!is_string($chunk) || $chunk === '') {
                return;
            }
        }
    }

    /** @param list<string> $command */
    private static function displayCommand(array $command): string
    {
        return implode(' ', array_map(
            static fn(string $argument): string => json_encode($argument, JSON_UNESCAPED_SLASHES) ?: '""',
            $command,
        ));
    }

    private static function drainFinalPipes(
        mixed $stdout,
        mixed $stderr,
        string &$stdoutBuffer,
        string &$stderrBuffer,
        int &$stdoutBytes,
        int &$stderrBytes,
        NativeExecutionLimits $limits,
    ): ?NativeExecutionFailure {
        return self::drainPipe(
            $stdout,
            $stdoutBuffer,
            $stdoutBytes,
            $limits->stdoutBytes,
            NativeExecutionFailure::STDOUT_LIMIT,
        ) ?? self::drainPipe(
            $stderr,
            $stderrBuffer,
            $stderrBytes,
            $limits->stderrBytes,
            NativeExecutionFailure::STDERR_LIMIT,
        );
    }

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
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            yield rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $command;
        }
    }

    /** @param list<string> $command */
    private static function isValidCommand(array $command): bool
    {
        if ($command === [] || $command[0] === '') {
            return false;
        }

        return array_all($command, static fn(string $argument): bool => !str_contains($argument, "\0"));
    }

    /** @return list<string> */
    private static function lines(string $buffer): array
    {
        $normalized = rtrim($buffer, "\r\n");
        if ($normalized === '') {
            return [];
        }

        $lines = preg_split('/\R/', $normalized);

        return $lines === false ? [] : $lines;
    }

    private static function locateExecutable(string $command): bool
    {
        if ($command === '' || str_contains($command, "\0")) {
            return false;
        }
        if (str_contains($command, '/') || str_contains($command, '\\')) {
            return is_file($command) && is_executable($command);
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return false;
        }

        foreach (self::executableCandidates($command, $path) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     * @return array{NativeExecutionFailure|null, int}
     */
    private static function monitorProcess(
        mixed $process,
        mixed $stdout,
        mixed $stderr,
        NativeExecutionLimits $limits,
        string &$stdoutBuffer,
        string &$stderrBuffer,
    ): array {
        $stdoutBytes = 0;
        $stderrBytes = 0;
        $deadline = self::deadlineFromNow($limits->timeoutSeconds);

        while (true) {
            $failure = self::drainPipe(
                $stdout,
                $stdoutBuffer,
                $stdoutBytes,
                $limits->stdoutBytes,
                NativeExecutionFailure::STDOUT_LIMIT,
            ) ?? self::drainPipe(
                $stderr,
                $stderrBuffer,
                $stderrBytes,
                $limits->stderrBytes,
                NativeExecutionFailure::STDERR_LIMIT,
            );
            $status = proc_get_status($process);

            if ($failure !== null) {
                $exitCode = $status['running']
                    ? self::terminateBounded($process, $stdout, $stderr, $limits)
                    : $status['exitcode'];

                return [$failure, $exitCode];
            }

            if (!$status['running']) {
                return [
                    self::drainFinalPipes(
                        $stdout,
                        $stderr,
                        $stdoutBuffer,
                        $stderrBuffer,
                        $stdoutBytes,
                        $stderrBytes,
                        $limits,
                    ),
                    $status['exitcode'],
                ];
            }

            if (hrtime(true) >= $deadline) {
                return [
                    NativeExecutionFailure::TIMEOUT,
                    self::terminateBounded($process, $stdout, $stderr, $limits),
                ];
            }

            usleep($limits->pollIntervalMicroseconds);
        }
    }

    private static function resolveExitCode(
        ?NativeExecutionFailure $failure,
        int $statusExitCode,
        int $closeExitCode,
    ): int {
        $failureExitCode = match ($failure) {
            NativeExecutionFailure::TIMEOUT => self::EXIT_TIMEOUT,
            NativeExecutionFailure::STDOUT_LIMIT, NativeExecutionFailure::STDERR_LIMIT => self::EXIT_OUTPUT_LIMIT,
            NativeExecutionFailure::IO_ERROR => self::EXIT_IO_ERROR,
            NativeExecutionFailure::START_FAILED, NativeExecutionFailure::UNSUPPORTED => self::EXIT_START_FAILED,
            default => null,
        };

        return $failureExitCode ?? ($statusExitCode >= 0 ? $statusExitCode : $closeExitCode);
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

    private static function startFailedResult(string $displayCommand, string $message): NativeExecutionResult
    {
        return new NativeExecutionResult(
            false,
            $displayCommand,
            self::EXIT_START_FAILED,
            [$message],
            NativeExecutionFailure::START_FAILED,
        );
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

    /** @param resource $process */
    private static function terminateBounded(
        mixed $process,
        mixed $stdout,
        mixed $stderr,
        NativeExecutionLimits $limits,
    ): int {
        self::runSilently(static fn(): bool => proc_terminate($process));
        $exitCode = self::waitForExit($process, $stdout, $stderr, $limits);
        if ($exitCode !== null) {
            return $exitCode;
        }

        self::runSilently(static fn(): bool => proc_terminate($process, 9));

        return self::waitForExit($process, $stdout, $stderr, $limits) ?? -1;
    }

    /** @param resource $process */
    private static function terminateImmediately(mixed $process): void
    {
        self::runSilently(static fn(): bool => proc_terminate($process));
        $status = proc_get_status($process);
        if ($status['running']) {
            self::runSilently(static fn(): bool => proc_terminate($process, 9));
        }
    }

    private static function unsupportedResult(): NativeExecutionResult
    {
        return new NativeExecutionResult(
            false,
            '',
            self::EXIT_START_FAILED,
            ['Bounded native execution is unavailable on this platform.'],
            NativeExecutionFailure::UNSUPPORTED,
        );
    }

    /** @param resource $process */
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
            if (!$status['running']) {
                return $status['exitcode'];
            }

            usleep($limits->pollIntervalMicroseconds);
        } while (hrtime(true) < $deadline);

        return null;
    }
}
