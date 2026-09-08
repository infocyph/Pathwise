<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Queue;

/**
 * An owned queue reservation. The lease token is required for every state-changing acknowledgement.
 */
final readonly class QueueReservation
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $leaseToken,
        public string $type,
        public array $payload,
        public int $priority,
        public int $createdAt,
        public int $reservedAt,
        public int $expiresAt,
    ) {}
}
