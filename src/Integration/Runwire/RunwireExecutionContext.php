<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Integration\Runwire;

use Infocyph\Pathwise\Indexing\ChecksumIndexer;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
use InvalidArgumentException;
use LogicException;

/**
 * A borrowed Runwire lifecycle. Pathwise never starts or owns its runtime, request or scope.
 */
final class RunwireExecutionContext
{
    private const int MAX_CHECKPOINT_INTERVAL = 1_000_000;

    private readonly ?TaskLocal $scopeProbe;

    private int $checkpoints = 0;

    public function __construct(
        public readonly RuntimeContext $runtime,
        public readonly ?RequestContext $request = null,
        public readonly ?CoroutineScope $scope = null,
        public readonly int $checkpointEvery = 256,
    ) {
        if ($checkpointEvery < 1 || $checkpointEvery > self::MAX_CHECKPOINT_INTERVAL) {
            throw new InvalidArgumentException('Runwire checkpoint interval must be between 1 and 1000000.');
        }

        $this->scopeProbe = $scope === null ? null : new TaskLocal();
        $this->assertActive();
    }

    public function assertActive(): void
    {
        if ($this->runtime->pid !== getmypid()) {
            throw new LogicException('Runwire runtime PID does not match the Pathwise process.');
        }
        if ($this->request !== null) {
            if ($this->request->runtime() !== $this->runtime || $this->request->completed()) {
                throw new LogicException('Runwire request is completed or belongs to another runtime.');
            }
            $this->request->cancellation->throwIfCancelled();
        }
        if ($this->scope !== null && $this->scopeProbe !== null) {
            // A Runwire 2.1.1 public guard checks the live scheduler/task and closed scope.
            $this->scope->hasLocal($this->scopeProbe);
            $this->scope->cancellation()->throwIfCancelled();
        }
    }

    public function checkpoint(): void
    {
        $this->assertActive();
        if (
            $this->scope !== null
            && $this->runtime->supports(RuntimeCapability::RUNWIRE_COROUTINES)
            && ++$this->checkpoints % $this->checkpointEvery === 0
        ) {
            $this->scope->yieldNow();
        }
        $this->assertActive();
    }

    /**
     * @return \Generator<int, array{checksum: string, path: string}>
     */
    public function iterateChecksums(string $directory, string $algorithm = 'sha256'): \Generator
    {
        $this->checkpoint();
        foreach (ChecksumIndexer::iterate($directory, $algorithm) as $entry) {
            $this->checkpoint();

            yield $entry;
        }
    }

    public function sleep(float $seconds): void
    {
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 86_400) {
            throw new InvalidArgumentException('Runwire wait must be finite and between zero and 86400 seconds.');
        }
        $this->assertActive();
        $end = hrtime(true) / 1_000_000_000 + $seconds;
        while (($remaining = $end - hrtime(true) / 1_000_000_000) > 0) {
            // The request and scope may have different cancellation sources; check both promptly.
            $pause = max(0.000001, min(
                $remaining,
                0.05,
                $this->request?->deadline()->remainingSeconds() ?? $remaining,
                $this->scope?->cancellation()->deadline()->remainingSeconds() ?? $remaining,
            ));
            if ($this->scope !== null && $this->runtime->supports(RuntimeCapability::RUNWIRE_COROUTINES)) {
                $this->scope->sleep($pause);
            } else {
                usleep(max(1, (int) ($pause * 1_000_000)));
            }
            $this->assertActive();
        }
    }
}
