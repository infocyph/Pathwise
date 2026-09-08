<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\FileManager;

use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\Results\SymlinkStatus;
use Infocyph\Pathwise\Utils\PathHelper;

final readonly class SafeSymlinkManager
{
    private string $linkRoot;

    private string $targetRoot;

    public function __construct(string $linkRoot, string $targetRoot)
    {
        $this->linkRoot = $this->canonicalRoot($linkRoot, 'Symlink root');
        $this->targetRoot = $this->canonicalRoot($targetRoot, 'Symlink target root');
    }

    /**
     * Create a symlink without replacing an existing path.
     *
     * Relative link paths are resolved below the configured link root and
     * relative target paths are resolved below the configured target root.
     */
    public function create(
        string $link,
        string $target,
        bool $createTargetDirectory = false,
        int $directoryPermissions = 0775,
    ): bool {
        $this->assertDirectoryPermissions($directoryPermissions);
        $link = $this->resolveLinkPath($link, true);
        $target = $this->resolveTargetPath($target);

        if (is_link($link)) {
            if (!$this->linkMatchesTarget($link, $target)) {
                throw new PolicyViolationException(sprintf('A different symbolic link already exists at "%s".', $link));
            }

            $this->prepareTarget($target, $createTargetDirectory, $directoryPermissions);

            return false;
        }
        if (file_exists($link)) {
            throw new PolicyViolationException(sprintf('A file or directory already exists at "%s".', $link));
        }

        $target = $this->prepareTarget($target, $createTargetDirectory, $directoryPermissions);
        if ($this->createNativeSymlink($target, $link)) {
            return true;
        }

        return $this->resolveConcurrentCreate($link, $target);
    }

    /**
     * Remove a symlink only when it still points to the expected target.
     */
    public function remove(string $link, string $expectedTarget): bool
    {
        $link = $this->resolveLinkPath($link, true);
        $expectedTarget = $this->resolveExpectedTarget($expectedTarget);

        if (!is_link($link)) {
            if (file_exists($link)) {
                throw new PolicyViolationException(sprintf('Refusing to remove non-symbolic path "%s".', $link));
            }

            return false;
        }
        if (!$this->linkMatchesTarget($link, $expectedTarget)) {
            throw new PolicyViolationException(sprintf(
                'Refusing to remove symbolic link "%s" because its current target does not match the expected target.',
                $link,
            ));
        }
        if (!$this->runSilently(static fn(): bool => unlink($link))) {
            throw new \RuntimeException(sprintf('Unable to remove symbolic link "%s".', $link));
        }

        return true;
    }

    /**
     * Inspect a symlink without changing it.
     */
    public function status(string $link, string $expectedTarget): SymlinkStatus
    {
        $link = $this->resolveLinkPath($link, false);
        $expectedTarget = $this->resolveExpectedTarget($expectedTarget);
        $linked = is_link($link);
        $exists = $linked || file_exists($link);
        $broken = $linked && realpath($link) === false;

        return new SymlinkStatus(
            link: $link,
            target: $expectedTarget,
            exists: $exists,
            linked: $linked,
            matches: $linked && $this->linkMatchesTarget($link, $expectedTarget),
            broken: $broken,
        );
    }

    private function assertDirectoryPermissions(int $permissions): void
    {
        if ($permissions < 0 || $permissions > 0777) {
            throw new \InvalidArgumentException('Directory permissions must be between 0000 and 0777.');
        }
    }

    private function assertInside(string $path, string $root, string $label, bool $allowRoot = true): void
    {
        $pathKey = $this->comparisonKey($path);
        $rootKey = $this->comparisonKey($root);
        if ($pathKey === $rootKey) {
            if ($allowRoot) {
                return;
            }

            throw new PolicyViolationException(sprintf('%s must remain below "%s".', $label, $root));
        }
        if (str_starts_with($pathKey, $this->rootPrefix($rootKey))) {
            return;
        }

        throw new PolicyViolationException(sprintf('%s must remain inside "%s".', $label, $root));
    }

    private function canonicalExistingTarget(string $target): string
    {
        $resolved = realpath($target);
        if ($resolved === false) {
            throw new PolicyViolationException(sprintf('Symlink target does not resolve: %s', $target));
        }

        $resolved = PathHelper::normalize($resolved);
        $this->assertInside($resolved, $this->targetRoot, 'Symlink target');

        return $resolved;
    }

    private function canonicalRoot(string $root, string $label): string
    {
        $root = trim($root);
        if ($root === '' || str_contains($root, "\0") || PathHelper::hasScheme($root)) {
            throw new \InvalidArgumentException($label . ' must be a non-empty local filesystem path.');
        }

        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException($label . ' must reference an existing directory.');
        }

        return PathHelper::normalize($resolved);
    }

    private function comparisonKey(string $path): string
    {
        $key = str_replace('\\', '/', PathHelper::normalize($path));
        if ($key !== '/' && preg_match('/^[A-Za-z]:\/$/', $key) !== 1) {
            $key = rtrim($key, '/');
        }

        return PHP_OS_FAMILY === 'Windows' ? strtolower($key) : $key;
    }

    private function createNativeSymlink(string $target, string $link): bool
    {
        return $this->runSilently(static fn(): bool => symlink($target, $link)) === true;
    }

    private function linkMatchesTarget(string $link, string $target): bool
    {
        $resolvedLink = realpath($link);
        $resolvedTarget = realpath($target);
        if ($resolvedLink !== false && $resolvedTarget !== false) {
            return $this->samePath($resolvedLink, $resolvedTarget);
        }

        $rawTarget = readlink($link);
        if (!is_string($rawTarget) || !PathHelper::isAbsolute($rawTarget)) {
            return false;
        }

        return $this->samePath($rawTarget, $target);
    }

    private function nearestExistingAncestor(string $path): string
    {
        $ancestor = $path;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new PolicyViolationException(sprintf('Path has no existing ancestor: %s', $path));
            }

            $ancestor = $parent;
        }

        return $ancestor;
    }

    private function prepareTarget(string $target, bool $createDirectory, int $permissions): string
    {
        if (file_exists($target) || is_link($target)) {
            return $this->canonicalExistingTarget($target);
        }
        if (!$createDirectory) {
            throw new \RuntimeException(sprintf('Symlink target does not exist: %s', $target));
        }

        $ancestor = realpath($this->nearestExistingAncestor($target));
        if ($ancestor === false) {
            throw new PolicyViolationException(sprintf('Unable to resolve symlink target ancestor: %s', $target));
        }
        $this->assertInside($ancestor, $this->targetRoot, 'Symlink target ancestor');

        $created = $this->runSilently(static fn(): bool => mkdir($target, $permissions, true));
        if (!$created && !is_dir($target)) {
            throw new \RuntimeException(sprintf('Unable to create symlink target directory "%s".', $target));
        }

        return $this->canonicalExistingTarget($target);
    }

    private function resolveCandidate(string $path, string $root, string $label, bool $allowRoot): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || PathHelper::hasScheme($path)) {
            throw new \InvalidArgumentException($label . ' must be a non-empty local filesystem path.');
        }
        if (preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $path) === 1) {
            throw new PolicyViolationException($label . ' cannot contain parent-directory traversal.');
        }

        $candidate = PathHelper::isAbsolute($path)
            ? PathHelper::normalize($path)
            : PathHelper::join($root, trim($path, '/\\'));
        $this->assertInside($candidate, $root, $label, $allowRoot);

        return $candidate;
    }

    private function resolveConcurrentCreate(string $link, string $target): bool
    {
        if (is_link($link) && $this->linkMatchesTarget($link, $target)) {
            return false;
        }
        if (is_link($link) || file_exists($link)) {
            throw new PolicyViolationException(sprintf('A different path appeared at symbolic link "%s".', $link));
        }

        throw new \RuntimeException(sprintf('Unable to create symbolic link "%s".', $link));
    }

    private function resolveExpectedTarget(string $target): string
    {
        $target = $this->resolveTargetPath($target);
        if (file_exists($target) || is_link($target)) {
            return $this->canonicalExistingTarget($target);
        }

        return $target;
    }

    private function resolveLinkPath(string $link, bool $requireParent): string
    {
        $link = $this->resolveCandidate($link, $this->linkRoot, 'Symlink path', false);
        $parent = realpath(dirname($link));
        if ($parent === false) {
            if (!$requireParent && !is_link($link) && !file_exists($link)) {
                return $link;
            }

            throw new \RuntimeException(sprintf('Symlink parent does not exist: %s', dirname($link)));
        }

        $parent = PathHelper::normalize($parent);
        $this->assertInside($parent, $this->linkRoot, 'Symlink parent');
        $link = PathHelper::join($parent, basename($link));
        $this->assertInside($link, $this->linkRoot, 'Symlink path', false);

        return $link;
    }

    private function resolveTargetPath(string $target): string
    {
        return $this->resolveCandidate($target, $this->targetRoot, 'Symlink target', true);
    }

    private function rootPrefix(string $root): string
    {
        return str_ends_with($root, '/') ? $root : $root . '/';
    }

    private function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return $this->comparisonKey($left) === $this->comparisonKey($right);
    }
}
