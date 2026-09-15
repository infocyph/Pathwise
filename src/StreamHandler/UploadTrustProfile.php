<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler;

enum UploadTrustProfile: string
{
    case STANDARD = 'standard';
    case UNTRUSTED_DATA = 'untrusted_data';
}
