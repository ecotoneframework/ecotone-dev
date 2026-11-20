<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class Account
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $accountId;

    #[CommandHandler]
    public static function open(OpenAccount $command): array
    {
        return [new AccountOpened($command->accountId)];
    }

    #[CommandHandler]
    public function deposit(Deposit $command): array
    {
        return [new MoneyDeposited($this->accountId, $command->amount)];
    }

    #[Apply]
    public function onOpened(AccountOpened $event): void
    {
        $this->accountId = $event->accountId;
    }

    #[Apply]
    public function onDeposited(MoneyDeposited $event): void
    {
    }
}
