<?php

declare(strict_types=1);

namespace App\Catalogue;

enum Tier: string
{
    case STANDARD = 'standard';
    case VIP = 'vip';
}
