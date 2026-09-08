<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

use Infocyph\Pathwise\Native\NativeExecutionFailure;

final readonly class NativeExecutionResult
{
    /**
     * @param list<string> $output
     * @param list<string> $stdout
     * @param list<string> $stderr
     */
    public function __construct(
        public bool $success,
        public string $command,
        public int $exitCode,
        public array $output = [],
        public ?NativeExecutionFailure $failure = null,
        public array $stdout = [],
        public array $stderr = [],
    ) {}
}
