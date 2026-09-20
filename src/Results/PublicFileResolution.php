<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Results;

use InvalidArgumentException;

final readonly class PublicFileResolution
{
    public function __construct(
        public string $path,
        public string $relativePath,
        public string $mimeType,
        public int $size,
        public int $lastModified,
    ) {
        if ($path === '' || $relativePath === '' || $mimeType === '') {
            throw new InvalidArgumentException('Resolved public-file metadata must not be empty.');
        }
        if ($size < 0 || $lastModified < 0) {
            throw new InvalidArgumentException('Resolved public-file size and timestamp must be non-negative.');
        }
    }
}
