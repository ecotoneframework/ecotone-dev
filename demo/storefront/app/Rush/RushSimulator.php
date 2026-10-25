<?php

declare(strict_types=1);

namespace App\Rush;

use App\Customer\RegisterCustomer;
use App\Ordering\FailOrder;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use App\Payment\Pay;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class RushSimulator
{
    public const SPREAD_MILLISECONDS = 5000;
    public const CHECK_EVERY_MILLISECONDS = 1000;
    public const MAX_CHECKS = 60;
    public const MAX_REFUSED_SEATS = 3;

    private const PICKING = 'picking';
    private const PAYING = 'paying';
    private const GAVE_UP = 'gave_up';

    public static function refusalOf(?array $concert, string $concertId, string $rushId): ?DomainException
    {
        if ($concert === null) {
            return new DomainException(sprintf('There is no concert %s in the storefront catalogue, so rush %s cannot send buyers to it. Start the rush on a concert listed on /concerts.', $concertId, $rushId));
        }
        if ($concert['status'] !== 'on_sale') {
            return new DomainException(sprintf('Concert %s is %s, not on sale, so rush %s cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing and start a new rush, or start the rush on a concert listed as on_sale on /concerts.', $concertId, $concert['status'], $rushId));
        }

        return null;
    }

    #[Asynchronous('storefront_async')]
    #[CommandHandler(endpointId: 'start_rush')]
    public function start(StartRush $command, #[Reference] Rushes $rushes, #[Reference] QueryBus $queryBus, #[Reference] CommandBus $commandBus): void
    {
        $refusal = self::refusalOf($queryBus->sendWithRouting('concert.detail', $command->concertId), $command->concertId, $command->rushId);
        if ($refusal !== null) {
            throw $refusal;
        }
        $started = $rushes->start(
            rushId: $command->rushId,
            concertId: $command->concertId,
            buyersRequested: $command->buyers,
            seatsPerBuyer: $command->seatsPerBuyer,
            declinedShare: $command->declinedShare,
            outageShare: $command->outageShare,
            bounceShare: $command->bounceShare,
            startedAt: Carbon::now()->toAtomString(),
        );
        if ($started === 0) {
            return;
        }
        for ($buyerNumber = 1; $buyerNumber <= $command->buyers; $buyerNumber++) {
            $commandBus->send(
                new JoinRush(rushId: $command->rushId, buyerNumber: $buyerNumber),
                metadata: [MessageHeaders::DELIVERY_DELAY => intdiv(($buyerNumber - 1) * self::SPREAD_MILLISECONDS, $command->buyers)],
            );
        }
    }

    #[Asynchronous('storefront_async')]
    #[CommandHandler(endpointId: 'join_rush')]
    public function join(JoinRush $command, #[Reference] Rushes $rushes, #[Reference] CommandBus $commandBus): void
    {
        if ($rushes->buyer($command->rushId, $command->buyerNumber) !== false) {
            return;
        }
        $rush = $rushes->find($command->rushId);
        $role = $this->rolesOf($rush)[$command->buyerNumber - 1];
        $customerId = $commandBus->send(new RegisterCustomer(name: 'Rush buyer ' . $command->buyerNumber, email: $role->emailOf($command->rushId, $command->buyerNumber)));
        $orderId = $commandBus->send(new StartOrder(customerId: $customerId, concertId: $rush['concert_id']));
        $rushes->addBuyer(
            rushId: $command->rushId,
            buyerNumber: $command->buyerNumber,
            customerId: $customerId,
            orderId: $orderId,
            concertId: $rush['concert_id'],
            role: $role->value,
        );
        $commandBus->send(new CheckRushBuyer(rushId: $command->rushId, buyerNumber: $command->buyerNumber, check: 1));
    }

    #[Asynchronous('storefront_async')]
    #[CommandHandler(endpointId: 'check_rush_buyer')]
    public function check(CheckRushBuyer $command, #[Reference] Rushes $rushes, #[Reference] QueryBus $queryBus, #[Reference] CommandBus $commandBus): void
    {
        $buyer = $rushes->buyer($command->rushId, $command->buyerNumber);
        if ($buyer === false || $buyer['stage'] !== self::PICKING || (int) $buyer['last_check'] !== $command->check - 1) {
            return;
        }
        $rush = $rushes->find($command->rushId);
        $order = $queryBus->sendWithRouting('order.detail', $buyer['order_id']);
        $heldSeatIds = array_column($order['heldSeats'], 'seatId');
        $pendingSeatId = $buyer['pending_seat_id'];
        $refusedSeatIds = json_decode($buyer['refused_seat_ids'], true, flags: JSON_THROW_ON_ERROR);
        if ($pendingSeatId !== null && in_array($pendingSeatId, $heldSeatIds, true)) {
            $pendingSeatId = null;
        } elseif ($pendingSeatId !== null && ($order['lastRefusal']['seatId'] ?? null) === $pendingSeatId) {
            $refusedSeatIds[] = $pendingSeatId;
            $pendingSeatId = null;
        }

        $stage = self::PICKING;
        if (! in_array($order['status'], ['started', 'seats_held'], true)) {
            $stage = self::GAVE_UP;
        } elseif (count($refusedSeatIds) >= self::MAX_REFUSED_SEATS) {
            $stage = $this->giveUp($commandBus, $buyer['order_id'], sprintf('Rush buyer %d gave up after ticketing refused %d seats.', $command->buyerNumber, count($refusedSeatIds)));
        } elseif ($pendingSeatId === null && count($heldSeatIds) >= (int) $rush['seats_per_buyer']) {
            $commandBus->send(new Pay(orderId: $buyer['order_id'], cardNumber: RushBuyerRole::from($buyer['role'])->cardNumber()));
            $stage = self::PAYING;
        } elseif ($pendingSeatId === null) {
            $pendingSeatId = $this->freeSeatFor($rushes, $queryBus, $rush['concert_id'], $buyer['order_id'], [...$heldSeatIds, ...$refusedSeatIds]);
            if ($pendingSeatId === null) {
                $stage = $this->giveUp($commandBus, $buyer['order_id'], sprintf('Rush buyer %d gave up: no free seat of the concert was left.', $command->buyerNumber));
            } else {
                $commandBus->send(new RequestSeat(orderId: $buyer['order_id'], seatId: $pendingSeatId));
            }
        } elseif ($command->check >= self::MAX_CHECKS) {
            $stage = $this->giveUp($commandBus, $buyer['order_id'], sprintf('Rush buyer %d gave up after waiting %d checks for ticketing to answer the hold of seat %s.', $command->buyerNumber, $command->check, $pendingSeatId));
        }

        $rushes->recordCheck(
            rushId: $command->rushId,
            buyerNumber: $command->buyerNumber,
            stage: $stage,
            pendingSeatId: $stage === self::PICKING ? (string) $pendingSeatId : '',
            refusedSeatIds: json_encode($refusedSeatIds, JSON_THROW_ON_ERROR),
            lastCheck: $command->check,
        );
        if ($stage === self::PICKING) {
            $commandBus->send(
                new CheckRushBuyer(rushId: $command->rushId, buyerNumber: $command->buyerNumber, check: $command->check + 1),
                metadata: [MessageHeaders::DELIVERY_DELAY => self::CHECK_EVERY_MILLISECONDS],
            );

            return;
        }
        $rushes->finishWhenEveryBuyerIsDone(rushId: $command->rushId, finishedAt: Carbon::now()->toAtomString());
    }

    #[QueryHandler('rush.state')]
    public function state(string $rushId, #[Reference] Rushes $rushes): ?array
    {
        $progress = Str::isUuid($rushId) ? $rushes->progressOf($rushId) : false;
        if ($progress === false) {
            return null;
        }

        return [
            'rushId' => $progress['rush_id'],
            'concertId' => $progress['concert_id'],
            'buyersRequested' => (int) $progress['buyers_requested'],
            'seatsPerBuyer' => (int) $progress['seats_per_buyer'],
            'created' => (int) $progress['created'],
            'held' => (int) $progress['held'],
            'paying' => (int) $progress['paying'],
            'paid' => (int) $progress['paid'],
            'failed' => (int) $progress['failed'],
            'gaveUp' => (int) $progress['gave_up'],
            'startedAt' => $this->atom($progress['started_at']),
            'finishedAt' => $progress['finished_at'] === null ? null : $this->atom($progress['finished_at']),
        ];
    }

    #[QueryHandler('rush.buyers')]
    public function buyers(string $rushId, #[Reference] Rushes $rushes): array
    {
        return array_map(fn (array $buyer) => [
            'buyerNumber' => (int) $buyer['buyer_number'],
            'customerId' => $buyer['customer_id'],
            'orderId' => $buyer['order_id'],
            'stage' => $buyer['stage'],
        ], Str::isUuid($rushId) ? $rushes->buyersOf($rushId) : []);
    }

    /**
     * @return list<RushBuyerRole>
     */
    private function rolesOf(array $rush): array
    {
        $buyers = (int) $rush['buyers_requested'];
        $roles = [
            ...array_fill(0, (int) round($buyers * $rush['declined_share'] / 100), RushBuyerRole::DECLINED),
            ...array_fill(0, (int) round($buyers * $rush['outage_share'] / 100), RushBuyerRole::OUTAGE),
            ...array_fill(0, (int) round($buyers * $rush['bounce_share'] / 100), RushBuyerRole::BOUNCE),
        ];
        $roles = array_pad(array_slice($roles, 0, $buyers), $buyers, RushBuyerRole::PAYING);

        return (new Randomizer(new Mt19937(crc32($rush['rush_id']))))->shuffleArray($roles);
    }

    private function freeSeatFor(Rushes $rushes, QueryBus $queryBus, string $concertId, string $orderId, array $seatIdsOfThisBuyer): ?string
    {
        $concert = $queryBus->sendWithRouting('concert.detail', $concertId);
        $seatIds = [];
        foreach ($concert['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $seatIds = [...$seatIds, ...array_column($row['seats'], 'seatId')];
            }
        }
        $freeSeatIds = array_values(array_diff(
            $seatIds,
            $queryBus->sendWithRouting('concert.taken_seats', ['concertId' => $concertId, 'excludingOrderId' => $orderId]),
            $rushes->pendingSeatIdsOf($concertId),
            $seatIdsOfThisBuyer,
        ));

        return $freeSeatIds === [] ? null : $freeSeatIds[array_rand($freeSeatIds)];
    }

    private function giveUp(CommandBus $commandBus, string $orderId, string $reason): string
    {
        $commandBus->send(new FailOrder(orderId: $orderId, reason: $reason));

        return self::GAVE_UP;
    }

    private function atom(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }
}
