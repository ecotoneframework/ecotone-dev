<?php

namespace App\ReadModel\WalletBalance;

use App\Domain\Event\MoneyWasAddedToWallet;
use App\Domain\Event\MoneyWasSubtractedFromWallet;
use App\Domain\Wallet;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\EventSourcing\EventStreamEmitter;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Reference;

#[Projection(self::PROJECTION_NAME)]
#[FromAggregateStream(Wallet::class)]
final class WalletBalanceProjection
{
    const PROJECTION_NAME = "wallet_balance";

    #[EventHandler]
    public function whenMoneyWasAdded(MoneyWasAddedToWallet $event, EventStreamEmitter $eventStreamEmitter, #[Reference] DocumentStore $documentStore): void
    {
        $wallet =  $this->getWalletFor($documentStore, $event->walletId);
        $wallet = $wallet->add($event->amount);
        $this->saveWallet($documentStore, $wallet);

        $eventStreamEmitter->emit([new WalletBalanceWasChanged($event->walletId, $wallet->currentBalance)]);
    }

    #[EventHandler]
    public function whenMoneyWasSubtract(MoneyWasSubtractedFromWallet $event, EventStreamEmitter $eventStreamEmitter, #[Reference] DocumentStore $documentStore): void
    {
        $wallet =  $this->getWalletFor($documentStore, $event->walletId);
        $wallet = $wallet->subtract($event->amount);
        $this->saveWallet($documentStore, $wallet);

        $eventStreamEmitter->emit([new WalletBalanceWasChanged($event->walletId, $wallet->currentBalance)]);
    }

    private function getWalletFor(DocumentStore $documentStore, string $walletId): WalletBalanceState
    {
        $wallet = $documentStore->findDocument(self::PROJECTION_NAME, $walletId);
        if (is_null($wallet)) {
            $wallet = new WalletBalanceState($walletId, 0);
        }

        return $wallet;
    }

    private function saveWallet(DocumentStore $documentStore, WalletBalanceState $wallet): void
    {
        $documentStore->upsertDocument(self::PROJECTION_NAME, $wallet->walletId, $wallet, DocumentStore::LAST_WRITE_WINS);
    }
}