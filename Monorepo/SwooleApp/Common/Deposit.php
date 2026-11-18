<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

final class Deposit
{
    public function __construct(public string $accountId, public int $amount)
    {
    }
}
