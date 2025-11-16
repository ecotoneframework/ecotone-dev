<?php

declare(strict_types=1);

namespace App\EventProjecting\PartitionedProjection\ReadModel;

use App\EventProjecting\PartitionedProjection\Domain\Event\WalletWasCreated;
use App\EventProjecting\PartitionedProjection\Domain\Event\WalletWasCredited;
use App\EventProjecting\PartitionedProjection\Domain\Event\WalletWasDebited;
use App\EventProjecting\PartitionedProjection\Domain\Wallet;
use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Projection;

#[Projection(self::NAME, partitionHeaderName: 'aggregate.id')]
#[FromStream('wallet_stream', Wallet::class)]
#[Asynchronous('async_projection')]
class WalletBalanceProjection
{
    public const NAME = 'wallet_balance';

    public function __construct(private Connection $connection)
    {
    }

    // ============================================
    // LIFECYCLE METHODS
    // ============================================

    /**
     * Called when projection is initialized
     * Creates the read model table
     */
    #[ProjectionInitialization]
    public function init(): void
    {
        $this->connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS wallet_balances (
                wallet_id VARCHAR(255) PRIMARY KEY,
                current_balance DECIMAL(19, 4) NOT NULL DEFAULT 0,
                total_debits DECIMAL(19, 4) NOT NULL DEFAULT 0,
                total_credits DECIMAL(19, 4) NOT NULL DEFAULT 0,
                transaction_count INT NOT NULL DEFAULT 0
            )
            SQL);
    }

    /**
     * Called when projection is deleted
     * Drops the read model table completely
     */
    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement(<<<SQL
            DROP TABLE IF EXISTS wallet_balances
            SQL);
    }

    // ============================================
    // EVENT HANDLERS
    // ============================================

    #[EventHandler]
    public function whenWalletWasCreated(WalletWasCreated $event): void
    {
        $this->connection->insert('wallet_balances', [
            'wallet_id' => $event->walletId,
            'current_balance' => $event->initialBalance,
            'total_debits' => 0,
            'total_credits' => 0,
            'transaction_count' => 0,
        ]);
    }

    #[EventHandler]
    public function whenWalletWasDebited(WalletWasDebited $event): void
    {
        $this->connection->executeStatement(
            <<<SQL
            UPDATE wallet_balances 
            SET current_balance = current_balance - ?,
                total_debits = total_debits + ?,
                transaction_count = transaction_count + 1
            WHERE wallet_id = ?
            SQL,
            [$event->amount, $event->amount, $event->walletId]
        );
    }

    #[EventHandler]
    public function whenWalletWasCredited(WalletWasCredited $event): void
    {
        $this->connection->executeStatement(
            <<<SQL
            UPDATE wallet_balances 
            SET current_balance = current_balance + ?,
                total_credits = total_credits + ?,
                transaction_count = transaction_count + 1
            WHERE wallet_id = ?
            SQL,
            [$event->amount, $event->amount, $event->walletId]
        );
    }

    // ============================================
    // QUERY HANDLERS
    // ============================================

    #[QueryHandler('wallet.getBalance')]
    public function getCurrentBalance(string $walletId): float
    {
        $result = $this->connection->fetchOne(
            'SELECT current_balance FROM wallet_balances WHERE wallet_id = ?',
            [$walletId]
        );
        return $result === false ? 0.0 : (float)$result;
    }

    #[QueryHandler('wallet.getStatistics')]
    public function getWalletStatistics(string $walletId): array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM wallet_balances WHERE wallet_id = ?',
            [$walletId]
        );

        if ($result === false) {
            return [
                'balance' => 0.0,
                'total_debits' => 0.0,
                'total_credits' => 0.0,
                'transaction_count' => 0,
            ];
        }

        return [
            'balance' => (float)$result['current_balance'],
            'total_debits' => (float)$result['total_debits'],
            'total_credits' => (float)$result['total_credits'],
            'transaction_count' => (int)$result['transaction_count'],
        ];
    }
}

