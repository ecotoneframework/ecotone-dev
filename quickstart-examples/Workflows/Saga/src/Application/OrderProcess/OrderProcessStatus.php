<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\OrderProcess;

enum OrderProcessStatus: string
{
    case PLACED = 'PLACED';
    case PAYMENT_FAILED = 'PAYMENT_FAILED';
    case CANCELLED  = 'CANCELLED';
    case READY_TO_BE_SHIPPED = 'READY_TO_BE_SHIPPED';
}