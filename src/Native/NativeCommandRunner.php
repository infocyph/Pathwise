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
            return self::startFailedResult('', 'No valid command was provided.');
        }

        $displayCommand = self::displayCommand($command);

        return PHP_OS_FAMILY === 'Windows'
            ? self::runWithOutputFiles($command, $workingDirectory, $limits, $displayCommand)
            : self::runWithPipes($command, $workingDirectory, $limits, $displayCommand);
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

    /** @param array{directory: string, stdout: string, stderr: string} $paths */
    private static function cleanupOutputDirectory(array $paths): void
    {
        foreach ([$paths['stdout'], $paths['stderr']] as $path) {
            if (is_file($path)) {
                self::runSilently(static fn(): bool => unlink($path));
            }
        }
        if (is_dir($paths['directory'])) {
            self::runSilently(static fn(): bool => rmdir($paths['directory']));
        }
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
            if ($chunk === false || $chunk === '') {
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

    private static function drainPipe(
        mixed $pipe,
        string &$buffer,
        int &$bytes,
        int $limit,
        NativeExecutionFailure $limitFailure,
    ): ?NativeExecutionFailure {
        while (is_resource($pipe) && !feof($pipe)) {
            $remaining = $limit - $bytes;
            $chunk = fread($pipe, min(self::READ_CHUNK_BYTES, max(1, $remaining + 1)));
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

    /** @param array{directory: string, stdout: string, stderr: string} $paths */
    private static function fileCaptureFailure(array $paths, NativeExecutionLimits $limits): ?NativeExecutionFailure
    {
        clearstatcache(true, $paths['stdout']);
        clearstatcache(true, $paths['stderr']);
        $stdoutSize = self::runSilently(static fn(): int|false => filesize($paths['stdout']));
        $stderrSize = self::runSilently(static fn(): int|false => filesize($paths['stderr']));

        if (!is_int($stdoutSize) || !is_int($stderrSize)) {
            return NativeExecutionFailure::IO_ERROR;
        }
        if ($stdoutSize > $limits->stdoutBytes) {
            return NativeExecutionFailure::STDOUT_LIMIT;
        }
        if ($stderrSize > $limits->stderrBytes) {
            return NativeExecutionFailure::STDERR_LIMIT;
        }

        return null;
    }

    /** @param list<string> $command */
    private static function isValidCommand(array $command): bool
    {
        if ($command === [] || $command[0] === '') {
            return false;
        }

        return !array_any($command, static fn(string $argument): bool => str_contains($argument, "\0"));
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

    /**
     * @param resource $process
     * @param array{directory: string, stdout: string, stderr: string} $paths
     * @return array{NativeExecutionFailure|null, int}
     */
    private static function monitorFileProcess(
        mixed $process,
        array $paths,
        NativeExecutionLimits $limits,
    ): array {
        $deadline = self::deadlineFromNow($limits->timeoutSeconds);

        while (true) {
            $failure = self::fileCaptureFailure($paths, $limits);
            $status = proc_get_status($process);
            if (!$status['running']) {
                return [$failure ?? self::fileCaptureFailure($paths, $limits), $status['exitcode']];
            }
            if ($failure !== null) {
                return [$failure, self::terminateFileProcess($process, $limits)];
            }
            if (hrtime(true) >= $deadline) {
                return [NativeExecutionFailure::TIMEOUT, self::terminateFileProcess($process, $limits)];
            }

            usleep($limits->pollIntervalMicroseconds);
        }
    }

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     * @return array{NativeExecutionFailure|null, int}
     */
    private static function monitorPipeProcess(
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
            if (!$status['running']) {
                if ($failure === null) {
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
                }

                return [$failure, $status['exitcode']];
            }
            if ($failure !== null) {
                return [$failure, self::terminatePipeProcess($process, $stdout, $stderr, $limits)];
            }
            if (hrtime(true) >= $deadline) {
                return [NativeExecutionFailure::TIMEOUT, self::terminatePipeProcess($process, $stdout, $stderr, $limits)];
            }

            usleep($limits->pollIntervalMicroseconds);
        }
    }

    private static function outputFileContents(string $path, int $limit): string
    {
        $contents = file_get_contents($path, false, null, 0, $limit);

        return is_string($contents) ? $contents : '';
    }

    /** @return array{directory: string, stdout: string, stderr: string}|null */
    private static function prepareOutputFiles(): ?array
    {
        try {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise-native-' . bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return null;
        }

        if (!self::runSilently(static fn(): bool => mkdir($directory, 0700))) {
            return null;
        }

        return [
            'directory' => $directory,
            'stdout' => $directory . DIRECTORY_SEPARATOR . 'stdout.log',
            'stderr' => $directory . DIRECTORY_SEPARATOR . 'stderr.log',
        ];
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

    /** @param list<string> $command */
    private static function runWithOutputFiles(
        array $command,
        ?string $workingDirectory,
        NativeExecutionLimits $limits,
        string $displayCommand,
    ): NativeExecutionResult {
        $paths = self::prepareOutputFiles();
        if ($paths === null) {
            return self::startFailedResult($displayCommand, 'Unable to allocate native command output files.');
        }

        try {
            $pipes = [];
            $process = self::startFileProcess($command, $workingDirectory, $paths, $pipes);
            if (!is_resource($process)) {
                return self::startFailedResult($displayCommand, 'Unable to start native command.');
            }

            self::closePipe($pipes[0] ?? null);
            [$failure, $statusExitCode] = self::monitorFileProcess($process, $paths, $limits);
            $closeExitCode = proc_close($process);

            return self::buildResult(
                $displayCommand,
                $failure,
                $statusExitCode,
                $closeExitCode,
                self::outputFileContents($paths['stdout'], $limits->stdoutBytes),
                self::outputFileContents($paths['stderr'], $limits->stderrBytes),
            );
        } finally {
            self::cleanupOutputDirectory($paths);
        }
    }

    /** @param list<string> $command */
    private static function runWithPipes(
        array $command,
        ?string $workingDirectory,
        NativeExecutionLimits $limits,
        string $displayCommand,
    ): NativeExecutionResult {
        $pipes = [];
        $process = self::startPipeProcess($command, $workingDirectory, $pipes);
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

            return self::startFailedResult($displayCommand, 'Unable to configure native command output pipes.');
        }

        $stdoutBuffer = '';
        $stderrBuffer = '';
        [$failure, $statusExitCode] = self::monitorPipeProcess(
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
     * @param array{directory: string, stdout: string, stderr: string} $paths
     * @param array<int, resource> $pipes
     * @return resource|false
     */
    private static function startFileProcess(
        array $command,
        ?string $workingDirectory,
        array $paths,
        array &$pipes,
    ): mixed {
        return self::runSilently(static function () use ($command, $workingDirectory, $paths, &$pipes): mixed {
            try {
                return proc_open(
                    $command,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['file', $paths['stdout'], 'wb'],
                        2 => ['file', $paths['stderr'], 'wb'],
                    ],
                    $pipes,
                    $workingDirectory,
                    null,
                    ['bypass_shell' => true],
                );
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /**
     * @param list<string> $command
     * @param array<int, resource> $pipes
     * @return resource|false
     */
    private static function startPipeProcess(array $command, ?string $workingDirectory, array &$pipes): mixed
    {
        return self::runSilently(static function () use ($command, $workingDirectory, &$pipes): mixed {
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
        });
    }

    /** @param resource $process */
    private static function terminateFileProcess(mixed $process, NativeExecutionLimits $limits): int
    {
        self::runSilently(static fn(): bool => proc_terminate($process));
        $exitCode = self::waitForFileProcessExit($process, $limits);
        if ($exitCode >= 0) {
            return $exitCode;
        }

        self::runSilently(static fn(): bool => proc_terminate($process, 9));

        return self::waitForFileProcessExit($process, $limits);
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

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function terminatePipeProcess(
        mixed $process,
        mixed $stdout,
        mixed $stderr,
        NativeExecutionLimits $limits,
    ): int {
        self::runSilently(static fn(): bool => proc_terminate($process));
        $exitCode = self::waitForPipeProcessExit($process, $stdout, $stderr, $limits);
        if ($exitCode >= 0) {
            return $exitCode;
        }

        self::runSilently(static fn(): bool => proc_terminate($process, 9));

        return self::waitForPipeProcessExit($process, $stdout, $stderr, $limits);
    }

    /** @param resource $process */
    private static function waitForFileProcessExit(mixed $process, NativeExecutionLimits $limits): int
    {
        $deadline = self::deadlineFromNow($limits->terminationGraceSeconds);

        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status['exitcode'];
            }

            usleep($limits->pollIntervalMicroseconds);
        } while (hrtime(true) < $deadline);

        return -1;
    }

    /**
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function waitForPipeProcessExit(
        mixed $process,
        mixed $stdout,
        mixed $stderr,
        NativeExecutionLimits $limits,
    ): int {
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

        return -1;
    }

    /** @return list<string> */
    private static function windowsExecutableExtensions(): array
    {
        $pathExtensions = getenv('PATHEXT');
        if (!is_string($pathExtensions) || $pathExtensions === '') {
            return ['.exe', '.com', '.bat', '.cmd'];
        }

        $extensions = [];
        foreach (explode(PATH_SEPARATOR, $pathExtensions) as $extension) {
            if ($extension !== '') {
                $extensions[] = strtolower($extension);
            }
        }

        return $extensions === [] ? ['.exe', '.com', '.bat', '.cmd'] : $extensions;
    }
}
