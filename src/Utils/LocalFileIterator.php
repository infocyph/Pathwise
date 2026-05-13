<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Utils;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LocalFileIterator
{
    /**
     * @return \Generator<int, SplFileInfo>
     */
    public static function files(string $directory): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || $item->isDir()) {
                continue;
            }

            yield $item;
        }
    }
}
