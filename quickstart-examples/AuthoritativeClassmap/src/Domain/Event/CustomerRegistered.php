<?php

namespace App\Domain\Event;

/*
 * licence Apache-2.0
 */
class CustomerRegistered
{
    public function __construct(
        public int $customerId,
    )
    {
    }
}