<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionReset;

#[Projection(self::NAME)]
#[FromStream('ecotone_event_stream')]
#[Asynchronous('ticketing_async')]
final class ConcertSeatMap
{
    public const NAME = 'concert_seat_map';

    public function __construct(private readonly Connection $connection)
    {
    }

    #[EventHandler(endpointId: 'concert_seat_map.seat_held')]
    public function seatHeld(SeatHeld $event): void
    {
        $this->changeSeat($event->concertId, $event->seatId, SeatStatus::HELD, $event->orderId, $event->expiresAt);
    }

    #[EventHandler(endpointId: 'concert_seat_map.hold_released')]
    public function holdReleased(HoldReleased $event): void
    {
        $this->changeSeat($event->concertId, $event->seatId, SeatStatus::FREE, null, null);
    }

    #[EventHandler(endpointId: 'concert_seat_map.hold_expired')]
    public function holdExpired(HoldExpired $event): void
    {
        $this->changeSeat($event->concertId, $event->seatId, SeatStatus::FREE, null, null);
    }

    #[EventHandler(endpointId: 'concert_seat_map.seat_sold')]
    public function seatSold(SeatSold $event): void
    {
        $this->changeSeat($event->concertId, $event->seatId, SeatStatus::SOLD, $event->orderId, null);
    }

    /**
     * @return list<array{seatId: string, seatCode: string, tier: string, status: string, orderId: ?string, expiresAt: ?string}>
     */
    #[QueryHandler('concert.seat_map')]
    public function seatMap(string $concertId, QueryBus $queryBus): array
    {
        $concert = $queryBus->sendWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId]);
        $rows = array_column(
            $this->connection->fetchAllAssociative('SELECT seat_id, status, order_id, expires_at FROM concert_seat_map WHERE concert_id = ?', [$concertId]),
            null,
            'seat_id',
        );

        return array_map(
            static function (array $seat) use ($rows): array {
                $row = $rows[$seat['seatId']] ?? null;

                return [
                    'seatId' => $seat['seatId'],
                    'seatCode' => $seat['seatCode'],
                    'tier' => $seat['tier'],
                    'status' => $row['status'] ?? SeatStatus::FREE->value,
                    'orderId' => $row['order_id'] ?? null,
                    'expiresAt' => isset($row['expires_at']) ? (new DateTimeImmutable($row['expires_at']))->format(DateTimeInterface::ATOM) : null,
                ];
            },
            $concert['seats'],
        );
    }

    #[ProjectionReset]
    public function reset(): void
    {
        $this->connection->executeStatement('DELETE FROM concert_seat_map');
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement('DELETE FROM concert_seat_map');
    }

    private function changeSeat(string $concertId, string $seatId, SeatStatus $status, ?string $orderId, ?DateTimeImmutable $expiresAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO concert_seat_map (concert_id, seat_id, status, order_id, expires_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (concert_id, seat_id) DO UPDATE SET status = EXCLUDED.status, order_id = EXCLUDED.order_id, expires_at = EXCLUDED.expires_at',
            [$concertId, $seatId, $status->value, $orderId, $expiresAt?->format(DateTimeInterface::ATOM)],
        );
    }
}
