<?php

namespace App\Schedule\Messaging\PeriodSchedules;

class InvoiceWasGenerated
{
    public function __construct(public readonly string $personId) {}
}