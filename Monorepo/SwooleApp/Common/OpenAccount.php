<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

final class OpenAccount
{
    public function __construct(public string $accountId)
    {
    }
}
