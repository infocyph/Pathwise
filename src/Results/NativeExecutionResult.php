<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

final readonly class NativeExecutionResult
{
    /** @param list<string> $output */
    public function __construct(
        public bool $success,
        public string $command,
        public int $exitCode,
        public array $output = [],
    ) {}
}
