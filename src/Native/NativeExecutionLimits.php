<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Native;

final readonly class NativeExecutionLimits
{
    public const int DEFAULT_POLL_INTERVAL_MICROSECONDS = 10_000;

    public const int DEFAULT_STDERR_BYTES = 4_194_304;

    public const int DEFAULT_STDOUT_BYTES = 4_194_304;

    public const float DEFAULT_TERMINATION_GRACE_SECONDS = 1.0;

    public const float DEFAULT_TIMEOUT_SECONDS = 300.0;

    public function __construct(
        public float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public int $stdoutBytes = self::DEFAULT_STDOUT_BYTES,
        public int $stderrBytes = self::DEFAULT_STDERR_BYTES,
        public float $terminationGraceSeconds = self::DEFAULT_TERMINATION_GRACE_SECONDS,
        public int $pollIntervalMicroseconds = self::DEFAULT_POLL_INTERVAL_MICROSECONDS,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Native execution timeout must be a finite positive number.');
        }
        if ($stdoutBytes < 1 || $stderrBytes < 1) {
            throw new \InvalidArgumentException('Native execution output limits must be positive integers.');
        }
        if (!is_finite($terminationGraceSeconds) || $terminationGraceSeconds <= 0.0) {
            throw new \InvalidArgumentException('Native execution termination grace must be a finite positive number.');
        }
        if ($pollIntervalMicroseconds < 1 || $pollIntervalMicroseconds > 1_000_000) {
            throw new \InvalidArgumentException(
                'Native execution poll interval must be between 1 and 1000000 microseconds.',
            );
        }
    }
}
