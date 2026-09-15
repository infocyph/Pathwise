<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

enum PublicFileSymlinkPolicy: string
{
    case ALLOW_WITHIN_ROOT = 'allow_within_root';

    case REJECT = 'reject';
}
