<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;

#[Aggregate]
final class Wallet
{
    private function __construct(#[Identifier] private string $walletId, private int $balance)
    {
    }

    #[CommandHandler('wallet.open')]
    public static function open(array $command): self
    {
        return new self($command['walletId'], 0);
    }

    #[CommandHandler('wallet.topUp')]
    public function topUp(array $command): void
    {
        $this->balance += (int) $command['amount'];
    }

    #[QueryHandler('wallet.balance')]
    public function balance(): int
    {
        return $this->balance;
    }
}
