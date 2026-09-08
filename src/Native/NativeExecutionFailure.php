<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Native;

enum NativeExecutionFailure: string
{
    case EXIT_CODE = 'exit_code';
    case IO_ERROR = 'io_error';
    case START_FAILED = 'start_failed';
    case STDERR_LIMIT = 'stderr_limit';
    case STDOUT_LIMIT = 'stdout_limit';
    case TIMEOUT = 'timeout';
    case UNSUPPORTED = 'unsupported';
}
