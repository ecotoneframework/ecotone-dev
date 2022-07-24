<?php

namespace App\Schedule\Messaging\PeriodSchedules;

class GenerateInvoice
{
    public function __construct(public string $personId) {}
}