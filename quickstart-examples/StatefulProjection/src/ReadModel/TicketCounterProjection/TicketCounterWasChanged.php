<?php

namespace App\ReadModel\TicketCounterProjection;

final class TicketCounterWasChanged
{
    public function __construct(public readonly int $currentAmount){}
}