<?php

namespace App\ReadModel\WalletBalance;

use App\Domain\Event\MoneyWasAddedToWallet;
use App\Domain\Event\MoneyWasSubtractedFromWallet;
use App\Domain\Wallet;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Modelling\Attribute\EventHandler;

#[Projection(self::PROJECTION_NAME, Wallet::class)]
final class WalletBalanceProjection
{
    const PROJECTION_NAME = "wallet_balance";

    public function __construct(private DocumentStore $documentStore) {}

    #[EventHandler]
    public function whenMoneyWasAdded(MoneyWasAddedToWallet $event, EventStreamEmitter $eventStreamEmitter): void
    {
        $wallet =  $this->getWalletFor($event->walletId);
        $wallet = $wallet->add($event->amount);
        $this->saveWallet($wallet);

        $eventStreamEmitter->emit([new WalletBalanceWasChanged($event->walletId, $wallet->currentBalance)]);
    }

    #[EventHandler]
    public function whenMoneyWasSubtract(MoneyWasSubtractedFromWallet $event, EventStreamEmitter $eventStreamEmitter): void
    {
        $wallet =  $this->getWalletFor($event->walletId);
        $wallet = $wallet->subtract($event->amount);
        $this->saveWallet($wallet);

        $eventStreamEmitter->emit([new WalletBalanceWasChanged($event->walletId, $wallet->currentBalance)]);
    }

    private function getWalletFor(string $walletId): WalletBalanceState
    {
        $wallet = $this->documentStore->findDocument(self::PROJECTION_NAME, $walletId);
        if (is_null($wallet)) {
            $wallet = new WalletBalanceState($walletId, 0);
        }

        return $wallet;
    }

    private function saveWallet(WalletBalanceState $wallet): void
    {
        $this->documentStore->upsertDocument(self::PROJECTION_NAME, $wallet->walletId, $wallet);
    }
}