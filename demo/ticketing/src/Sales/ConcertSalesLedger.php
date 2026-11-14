<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Tier;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Projecting\FromTaggedStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\PartitionTagValue;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\ProjectionReset;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Projection(self::NAME)]
#[FromTaggedStream('concert')]
#[Partitioned]
#[Asynchronous('ticketing_async')]
#[ProjectionRebuild(partitionBatchSize: 1, asyncChannelName: 'ticketing_async')]
#[ProjectionBackfill(backfillPartitionBatchSize: 1, asyncChannelName: 'ticketing_async')]
final class ConcertSalesLedger
{
    public const NAME = 'concert_sales_ledger';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%ticketingSalesLedgerResetFailsForConcert%')] private readonly string $resetFailsForConcert,
    ) {
    }

    #[EventHandler(endpointId: 'concert_sales_ledger.seat_sold')]
    public function seatSold(SeatSold $event): void
    {
        $this->connection->executeStatement(
            'INSERT INTO concert_sales_ledger (concert_id, tier, seats_sold, revenue_cents) VALUES (?, ?, 1, ?)
             ON CONFLICT (concert_id, tier) DO UPDATE SET seats_sold = concert_sales_ledger.seats_sold + 1, revenue_cents = concert_sales_ledger.revenue_cents + EXCLUDED.revenue_cents',
            [$event->concertId, $event->tier->value, $event->priceCents],
        );
    }

    /**
     * @return array{concertId: string, seatCount: int, seatsSold: int, revenueCents: int, tiers: array<string, array{seatsSold: int, revenueCents: int}>}
     */
    #[QueryHandler('concert.sales')]
    public function sales(string $concertId, QueryBus $queryBus): array
    {
        $concert = $queryBus->sendWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId]);
        $tiers = [];
        foreach (Tier::cases() as $tier) {
            $tiers[$tier->value] = ['seatsSold' => 0, 'revenueCents' => 0];
        }
        foreach ($this->connection->fetchAllAssociative('SELECT tier, seats_sold, revenue_cents FROM concert_sales_ledger WHERE concert_id = ?', [$concertId]) as $row) {
            $tiers[$row['tier']] = ['seatsSold' => (int) $row['seats_sold'], 'revenueCents' => (int) $row['revenue_cents']];
        }

        return [
            'concertId' => $concertId,
            'seatCount' => count($concert['seats']),
            'seatsSold' => array_sum(array_column($tiers, 'seatsSold')),
            'revenueCents' => array_sum(array_column($tiers, 'revenueCents')),
            'tiers' => $tiers,
        ];
    }

    #[ProjectionReset]
    public function reset(#[PartitionTagValue] string $concertId): void
    {
        if ($this->resetFailsForConcert !== '' && $concertId === $this->resetFailsForConcert) {
            throw new RuntimeException(sprintf('The sales ledger of concert %s cannot be reset, because TICKETING_SALES_LEDGER_RESET_FAILS_FOR_CONCERT is set to %s to simulate a failing rebuild batch. Unset TICKETING_SALES_LEDGER_RESET_FAILS_FOR_CONCERT and recreate the container (docker compose up -d ticketing-worker), because a restart keeps the old environment, then replay the batch from the dead letters in Ecotone Pulse or with bin/console ecotone:deadletter:replay.', $concertId, $this->resetFailsForConcert));
        }
        $this->connection->executeStatement('DELETE FROM concert_sales_ledger WHERE concert_id = ?', [$concertId]);
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement('DELETE FROM concert_sales_ledger');
    }
}
