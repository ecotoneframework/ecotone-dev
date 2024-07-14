<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixture;

/**
 * licence Apache-2.0
 */
class AddMoney
{
    public int $personId;
    public int $amount;

    public function __construct(int $personId, int $amount)
    {
        $this->personId = $personId;
        $this->amount = $amount;
    }
}
