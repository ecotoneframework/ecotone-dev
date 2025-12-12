<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\Domain;

use App\EventProjecting\PartitionedProjection\Domain\Command\CreateWallet;
use App\EventProjecting\PartitionedProjection\Domain\Command\CreditWallet;
use App\EventProjecting\PartitionedProjection\Domain\Command\DebitWallet;
use App\EventProjecting\PartitionedProjection\Domain\Event\WalletWasCreated;
use App\EventProjecting\PartitionedProjection\Domain\Event\WalletWasCredited;
use App\EventProjecting\PartitionedProjection\Domain\Event\WalletWasDebited;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[Stream('wallet_stream')]
class Wallet
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    private float $balance;

    #[CommandHandler]
    public static function create(CreateWallet $command): array
    {
        return [new WalletWasCreated($command->walletId, $command->initialBalance)];
    }

    #[CommandHandler]
    public function debit(DebitWallet $command): array
    {
        if ($command->amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive');
        }

        if ($this->balance < $command->amount) {
            throw new \DomainException('Insufficient funds');
        }

        return [new WalletWasDebited($this->walletId, $command->amount)];
    }

    #[CommandHandler]
    public function credit(CreditWallet $command): array
    {
        if ($command->amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive');
        }

        return [new WalletWasCredited($this->walletId, $command->amount)];
    }

    #[EventSourcingHandler]
    public function applyWalletWasCreated(WalletWasCreated $event): void
    {
        $this->walletId = $event->walletId;
        $this->balance = $event->initialBalance;
    }

    #[EventSourcingHandler]
    public function applyWalletWasDebited(WalletWasDebited $event): void
    {
        $this->balance -= $event->amount;
    }

    #[EventSourcingHandler]
    public function applyWalletWasCredited(WalletWasCredited $event): void
    {
        $this->balance += $event->amount;
    }
}

