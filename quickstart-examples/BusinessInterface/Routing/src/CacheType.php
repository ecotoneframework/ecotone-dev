<?php

declare(strict_types=1);

namespace App\BusinessInterface;

enum CacheType: string
{
    case IN_MEMORY = 'in_memory';
    case FILE_SYSTEM = 'file_system';
}