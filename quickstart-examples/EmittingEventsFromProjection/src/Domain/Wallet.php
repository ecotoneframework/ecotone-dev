<?php

namespace App\Domain;

use App\Domain\Command\AddMoneyToWallet;
use App\Domain\Command\SubtractMoneyFromWallet;
use App\Domain\Event\MoneyWasAddedToWallet;
use App\Domain\Event\MoneyWasSubtractedFromWallet;
use App\Domain\Event\WalletWasInitialized;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class Wallet
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    #[CommandHandler]
    public static function startWalletByAdding(AddMoneyToWallet $command): array
    {
        return [new WalletWasInitialized($command->walletId), new MoneyWasAddedToWallet($command->walletId, $command->amount)];
    }

    #[CommandHandler]
    public static function startWalletBySubtracting(SubtractMoneyFromWallet $command): array
    {
        return [new WalletWasInitialized($command->walletId), new MoneyWasSubtractedFromWallet($command->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function add(AddMoneyToWallet $command): array
    {
        return [new MoneyWasAddedToWallet($command->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function subtract(SubtractMoneyFromWallet $command): array
    {
        return [new MoneyWasSubtractedFromWallet($command->walletId, $command->amount)];
    }

    #[EventSourcingHandler]
    public function applyWalletWasInitialized(WalletWasInitialized $event): void
    {
        $this->walletId = $event->walletId;
    }
}