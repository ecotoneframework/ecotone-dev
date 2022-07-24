<?php

namespace App\Schedule\Messaging\PeriodSchedules;

class UserWasRegistered
{
    public function __construct(public readonly string $personId) {}
}