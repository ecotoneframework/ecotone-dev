<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

final class AccountOpened
{
    public function __construct(public string $accountId)
    {
    }
}
