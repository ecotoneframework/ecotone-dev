<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

final class MoneyDeposited
{
    public function __construct(public string $accountId, public int $amount)
    {
    }
}
