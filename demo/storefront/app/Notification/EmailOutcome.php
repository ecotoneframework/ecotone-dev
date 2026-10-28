<?php

declare(strict_types=1);

namespace App\Notification;

enum EmailOutcome: string
{
    case DELIVERED = 'delivered';
    case BOUNCES = 'bounces';
}
