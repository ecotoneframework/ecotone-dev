<?php

namespace App\ReadModel\TicketCounterProjection;

final class TicketCounterState
{
    public function __construct(public readonly int $count) {}

    public function increase(): self
    {
        return new self($this->count + 1);
    }
}