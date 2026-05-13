<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Benchmarks;

use Infocyph\Pathwise\Utils\PathHelper;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(6)]
#[Bench\Revs(1500)]
final class PathHelperBench
{
    public function benchJoinMultipleSegments(): void
    {
        PathHelper::join(
            '/var',
            'www',
            'project',
            'storage',
            'uploads',
            '../uploads',
            '2026',
            '05',
            '13',
            'artifact.zip',
        );
    }

    public function benchNormalizeDeepRelativePath(): void
    {
        PathHelper::normalize('/var/www/project/src/../tests/./fixtures/../../src/Utils/PathHelper.php');
    }

    public function benchRelativePathComputation(): void
    {
        PathHelper::relativePath(
            '/var/www/project/modules/pathwise/src',
            '/var/www/project/modules/pathwise/tests/Feature/UploadProcessorTest.php',
        );
    }
}
