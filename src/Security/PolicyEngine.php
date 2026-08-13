<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Security;

use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\Utils\PathHelper;

final class PolicyEngine
{
    /**
     * @var array<int, array{
     *   operation: string,
     *   pattern: string,
     *   allow: bool,
     *   condition: (callable(string, string, array<string, mixed>): bool)|null
     * }>
     */
    private array $rules = [];

    /**
     * Allow an operation matching the given pattern.
     *
     * @param string $operation The operation to allow (e.g., 'read', 'write', '*').
     * @param string $pattern The path pattern to match (e.g., '/path/*').
     * @param callable|null $condition Optional condition callback that must return true.
     * @return self This instance for method chaining.
     */
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

    /**
     * Assert that an operation is allowed on a path.
     *
     * @param string $operation The operation to check.
     * @param string $path The path to check.
     * @param array<string, mixed> $context Additional context for condition evaluation.
     * @throws PolicyViolationException If the operation is not allowed.
     */
    public function assertAllowed(string $operation, string $path, array $context = []): void
    {
        if (!$this->isAllowed($operation, $path, $context)) {
            throw new PolicyViolationException("Operation '{$operation}' is denied for path '{$path}'.");
        }
    }

    /**
     * Deny an operation matching the given pattern.
     *
     * @param string $operation The operation to deny (e.g., 'read', 'write', '*').
     * @param string $pattern The path pattern to match (e.g., '/path/*').
     * @param callable|null $condition Optional condition callback that must return true.
     * @return self This instance for method chaining.
     */
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

    /**
     * Check if an operation is allowed on a path.
     *
     * @param string $operation The operation to check.
     * @param string $path The path to check.
     * @param array<string, mixed> $context Additional context for condition evaluation.
     *                                      Rules use last-match-wins precedence. Returns true when allowed.
     */
    public function isAllowed(string $operation, string $path, array $context = []): bool
    {
        $decision = true;
        $normalizedPath = str_replace('\\', '/', $path);
        $caseInsensitive = PHP_OS_FAMILY === 'Windows' && !PathHelper::hasScheme($path);
        if ($caseInsensitive) {
            $normalizedPath = strtolower($normalizedPath);
        }

        foreach ($this->rules as $rule) {
            if ($rule['operation'] !== '*' && $rule['operation'] !== $operation) {
                continue;
            }
            $pattern = str_replace('\\', '/', $rule['pattern']);
            if ($caseInsensitive) {
                $pattern = strtolower($pattern);
            }
            if (!fnmatch($pattern, $normalizedPath)) {
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
