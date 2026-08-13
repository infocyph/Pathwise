<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Exceptions;

use Infocyph\Pathwise\Results\NativeExecutionResult;

final class NativeExecutionException extends \RuntimeException implements PathwiseException
{
    public function __construct(
        string $message,
        public readonly ?NativeExecutionResult $result = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
