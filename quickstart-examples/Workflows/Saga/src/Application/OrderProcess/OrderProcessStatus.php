<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\OrderProcess;

enum OrderProcessStatus
{
    case PLACED;
    case PAYMENT_FAILED;
    case CANCELLED;
    case READY_TO_BE_SHIPPED;
}