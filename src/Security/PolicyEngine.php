<?php

namespace Infocyph\Pathwise\Security;

use Infocyph\Pathwise\Exceptions\PolicyViolationException;

final class PolicyEngine
{
    /**
     * @var array<int, array{operation: string, pattern: string, allow: bool, condition: callable|null}>
     */
    private array $rules = [];

    public function allow(string $operation, string $pattern = '*', ?callable $condition = null): self
    {
        $this->rules[] = [
            'operation' => $operation,
            'pattern' => $pattern,
            'allow' => true,
            'condition' => $condition,
        ];

        return $this;
    }

    public function assertAllowed(string $operation, string $path, array $context = []): void
    {
        if (!$this->isAllowed($operation, $path, $context)) {
            throw new PolicyViolationException("Operation '{$operation}' is denied for path '{$path}'.");
        }
    }

    public function deny(string $operation, string $pattern = '*', ?callable $condition = null): self
    {
        $this->rules[] = [
            'operation' => $operation,
            'pattern' => $pattern,
            'allow' => false,
            'condition' => $condition,
        ];

        return $this;
    }

    public function isAllowed(string $operation, string $path, array $context = []): bool
    {
        $decision = true;

        foreach ($this->rules as $rule) {
            if ($rule['operation'] !== '*' && $rule['operation'] !== $operation) {
                continue;
            }
            if (!fnmatch($rule['pattern'], str_replace('\\', '/', $path))) {
                continue;
            }
            if ($rule['condition'] !== null && !($rule['condition'])($operation, $path, $context)) {
                continue;
            }

            $decision = $rule['allow'];
        }

        return $decision;
    }
}
