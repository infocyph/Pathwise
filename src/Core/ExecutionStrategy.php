<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Core;

enum ExecutionStrategy: string
{
    case AUTO = 'auto';

    case NATIVE = 'native';

    case PHP = 'php';
}
