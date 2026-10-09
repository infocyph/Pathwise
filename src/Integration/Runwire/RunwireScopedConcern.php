<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Integration\Runwire;

use Fiber;
use LogicException;
use WeakMap;

/** @internal Borrowed context bindings are per owner and current Fiber/main execution. */
trait RunwireScopedConcern
{
    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, RunwireExecutionContext>|null */
    private ?WeakMap $runwireFiberContexts = null;

    private ?RunwireExecutionContext $runwireMainContext = null;

    /** @var array<string, int> */
    private array $runwireLatestGenerations = [];

    protected function checkpointRunwire(): void
    {
        $context = $this->currentRunwireContext();
        if ($context !== null) {
            $this->assertRunwireGeneration($context);
            $context->checkpoint();
        }
    }

    /**
     * @template T
     * @param callable(static): T $operation
     * @return T
     */
    public function withRunwire(RunwireExecutionContext $context, callable $operation): mixed
    {
        $fiber = Fiber::getCurrent();
        $previous = $this->currentRunwireContext();
        if ($previous !== null && $previous !== $context) {
            throw new LogicException('Nested Pathwise Runwire bindings must use the same execution context.');
        }

        $context->assertActive();
        $this->assertRunwireGeneration($context);
        $this->storeRunwireContext($fiber, $context);

        try {
            return $operation($this);
        } finally {
            $this->storeRunwireContext($fiber, $previous);
        }
    }

    private function assertRunwireGeneration(RunwireExecutionContext $context): void
    {
        $runtime = $context->runtime;
        if ($runtime->generation === null) {
            return;
        }

        $key = $runtime->driver->value . ':' . $runtime->mode . ':' . ($runtime->workerSlot ?? -1);
        $latest = $this->runwireLatestGenerations[$key] ?? null;
        if ($latest !== null && $runtime->generation < $latest) {
            throw new LogicException('Stale Runwire runtime generation cannot enter Pathwise.');
        }

        $this->runwireLatestGenerations[$key] = $runtime->generation;
    }

    private function currentRunwireContext(): ?RunwireExecutionContext
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->runwireMainContext : ($this->runwireFiberContexts[$fiber] ?? null);
    }

    /**
     * @param Fiber<mixed, mixed, mixed, mixed>|null $fiber
     */
    private function storeRunwireContext(?Fiber $fiber, ?RunwireExecutionContext $context): void
    {
        if ($fiber === null) {
            $this->runwireMainContext = $context;

            return;
        }
        if ($context === null) {
            if ($this->runwireFiberContexts !== null) {
                unset($this->runwireFiberContexts[$fiber]);
            }

            return;
        }

        $this->runwireFiberContexts ??= new WeakMap();
        $this->runwireFiberContexts[$fiber] = $context;
    }
}
