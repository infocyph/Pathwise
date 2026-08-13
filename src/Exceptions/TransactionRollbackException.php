<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Exceptions;

final class TransactionRollbackException extends \RuntimeException implements PathwiseException
{
    /**
     * @param list<\Throwable> $rollbackFailures
     */
    public function __construct(
        public readonly \Throwable $originalFailure,
        public readonly array $rollbackFailures,
    ) {
        $details = array_map(
            static fn(\Throwable $failure): string => $failure->getMessage(),
            $rollbackFailures,
        );
        parent::__construct(
            'Transaction failed and rollback was incomplete: ' . implode('; ', $details),
            0,
            $originalFailure,
        );
    }
}
