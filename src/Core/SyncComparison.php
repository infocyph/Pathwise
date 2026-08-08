<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Core;

enum SyncComparison
{
    case ALWAYS_COPY;

    case CHECKSUM;

    case SIZE;

    case SIZE_AND_MODIFIED_TIME;
}
