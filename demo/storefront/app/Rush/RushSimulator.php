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
    public const TICK_EVERY_MILLISECONDS = 2000;
    public const TICK_WHILE_WAITING_ON_TICKETING_MILLISECONDS = 5000;
    public const JOINS_PER_TICK = 20;
    public const BUYER_STEPS_PER_TICK = 10;
    public const MAX_REFUSED_SEATS = 3;

    private const PICKING = 'picking';
    private const PAYING = 'paying';
    private const GAVE_UP = 'gave_up';
    private const OPEN_ORDER_STATUSES = ['started', 'seats_held'];

    public static function refusalOf(?array $concert, string $concertId): ?DomainException
    {
        if ($concert === null) {
            return new DomainException(sprintf('There is no concert %s in the storefront catalogue, so a rush cannot send buyers to it. Start the rush on a concert listed on /concerts.', $concertId));
        }
        if ($concert['status'] !== 'on_sale') {
            return new DomainException(sprintf('Concert %s is %s, not on sale, so a rush cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing and start a new rush, or start the rush on a concert listed as on_sale on /concerts.', $concertId, $concert['status']));
        }

        return null;
    }

    #[Asynchronous('storefront_async')]
    #[CommandHandler(endpointId: 'start_rush')]
    public function start(StartRush $command, #[Reference] Rushes $rushes, #[Reference] QueryBus $queryBus, #[Reference] CommandBus $commandBus): void
    {
        $refusal = self::refusalOf($queryBus->sendWithRouting('concert.detail', $command->concertId), $command->concertId);
        if ($refusal !== null) {
            throw $refusal;
        }
        $startedAt = Carbon::now();
        $started = $rushes->start(
            rushId: $command->rushId,
            concertId: $command->concertId,
            buyersRequested: $command->buyers,
            seatsPerBuyer: $command->seatsPerBuyer,
            declinedShare: $command->declinedShare,
            outageShare: $command->outageShare,
            bounceShare: $command->bounceShare,
            buyerDeadlineSeconds: (int) config('demo.rush_buyer_deadline_seconds'),
            startedAt: $startedAt->toAtomString(),
            deadlineAt: $startedAt->copy()->addSeconds((int) config('demo.rush_deadline_seconds'))->toAtomString(),
        );
        if ($started === 0) {
            return;
        }
        $commandBus->send(new AdvanceRush(rushId: $command->rushId, tick: 1));
    }

    #[Asynchronous('storefront_async')]
    #[CommandHandler(endpointId: 'advance_rush')]
    public function advance(AdvanceRush $command, #[Reference] Rushes $rushes, #[Reference] QueryBus $queryBus, #[Reference] CommandBus $commandBus): void
    {
        if ($rushes->claimTick(rushId: $command->rushId, tick: $command->tick) === 0) {
            return;
        }
        $rush = $rushes->find($command->rushId);
        $now = Carbon::now();
        $deadline = Carbon::parse($rush['deadline_at']);
        $pastDeadline = $now->greaterThanOrEqualTo($deadline);
        $freeSeatIds = $this->freeSeatIdsOf($rushes, $queryBus, $rush['concert_id']);
        $stepsLeft = self::BUYER_STEPS_PER_TICK;
        $stillPicking = 0;
        $everyBuyerWaitsOnTicketing = true;

        foreach ($rushes->buyersPickingOf($command->rushId) as $buyer) {
            $step = $this->advanceBuyer($buyer, $rush, $now, $pastDeadline, $freeSeatIds, $stepsLeft > 0, $commandBus);
            if ($step['acted']) {
                $stepsLeft--;
            }
            if ($step['stage'] === self::PICKING) {
                $stillPicking++;
                $everyBuyerWaitsOnTicketing = $everyBuyerWaitsOnTicketing && $step['pendingSeatId'] !== null;
            }
            $rushes->recordProgress(
                rushId: $command->rushId,
                buyerNumber: (int) $buyer['buyer_number'],
                stage: $step['stage'],
                pendingSeatId: $step['stage'] === self::PICKING ? (string) $step['pendingSeatId'] : '',
                refusedSeatIds: json_encode($step['refusedSeatIds'], JSON_THROW_ON_ERROR),
                recordedAt: $now->toAtomString(),
            );
        }

        $joined = $rushes->buyersJoinedOf($command->rushId);
        $buyersRequested = (int) $rush['buyers_requested'];
        if (! $pastDeadline && $joined < $buyersRequested) {
            $roles = $this->rolesOf($rush);
            $joining = min($buyersRequested, $joined + self::JOINS_PER_TICK);
            for ($buyerNumber = $joined + 1; $buyerNumber <= $joining; $buyerNumber++) {
                $this->join($rushes, $commandBus, $rush, $roles[$buyerNumber - 1], $buyerNumber, $now->toAtomString());
            }
            $stillPicking += $joining - $joined;
            $everyBuyerWaitsOnTicketing = false;
        }

        if ($stillPicking === 0) {
            $rushes->finish(rushId: $command->rushId, finishedAt: $now->toAtomString());

            return;
        }
        $nextTickIn = $everyBuyerWaitsOnTicketing && $stepsLeft > 0 ? self::TICK_WHILE_WAITING_ON_TICKETING_MILLISECONDS : self::TICK_EVERY_MILLISECONDS;
        $commandBus->send(
            new AdvanceRush(rushId: $command->rushId, tick: $command->tick + 1),
            metadata: [MessageHeaders::DELIVERY_DELAY => $pastDeadline ? self::TICK_EVERY_MILLISECONDS : min($nextTickIn, (int) $now->diffInMilliseconds($deadline))],
        );
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
            'issued' => (int) $progress['issued'],
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
     * @param list<string> $freeSeatIds
     * @return array{stage: string, pendingSeatId: ?string, refusedSeatIds: list<string>, acted: bool}
     */
    private function advanceBuyer(array $buyer, array $rush, Carbon $now, bool $pastDeadline, array &$freeSeatIds, bool $mayAct, CommandBus $commandBus): array
    {
        $buyerNumber = (int) $buyer['buyer_number'];
        $heldSeatIds = array_column(json_decode($buyer['held_seats'], true, flags: JSON_THROW_ON_ERROR), 'seatId');
        $lastRefusal = $buyer['last_refusal'] === null ? null : json_decode($buyer['last_refusal'], true, flags: JSON_THROW_ON_ERROR);
        $pendingSeatId = $buyer['pending_seat_id'];
        $refusedSeatIds = json_decode($buyer['refused_seat_ids'], true, flags: JSON_THROW_ON_ERROR);
        if ($pendingSeatId !== null && in_array($pendingSeatId, $heldSeatIds, true)) {
            $pendingSeatId = null;
        } elseif ($pendingSeatId !== null && ($lastRefusal['seatId'] ?? null) === $pendingSeatId) {
            $refusedSeatIds[] = $pendingSeatId;
            $pendingSeatId = null;
        }
        if (! in_array($buyer['status'], self::OPEN_ORDER_STATUSES, true)) {
            return $this->step(stage: self::GAVE_UP, pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: false);
        }
        if (! $mayAct) {
            return $this->step(stage: self::PICKING, pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: false);
        }
        if (count($refusedSeatIds) >= self::MAX_REFUSED_SEATS) {
            return $this->step(stage: $this->giveUp($commandBus, $buyer['order_id'], sprintf('Rush buyer %d gave up after ticketing refused %d seats.', $buyerNumber, count($refusedSeatIds))), pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: true);
        }
        if ($pastDeadline) {
            return $this->step(stage: $this->giveUp($commandBus, $buyer['order_id'], sprintf('Rush buyer %d gave up: the rush ended %d seconds after it started, the deadline of a rush set by RUSH_DEADLINE_SECONDS.', $buyerNumber, (int) Carbon::parse($rush['started_at'])->diffInSeconds(Carbon::parse($rush['deadline_at'])))), pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: true);
        }
        if ($pendingSeatId === null && count($heldSeatIds) >= (int) $rush['seats_per_buyer']) {
            $commandBus->send(new Pay(orderId: $buyer['order_id'], cardNumber: RushBuyerRole::from($buyer['role'])->cardNumber()));

            return $this->step(stage: self::PAYING, pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: true);
        }
        if ($buyer['asked_at'] !== null && $now->greaterThanOrEqualTo(Carbon::parse($buyer['asked_at'])->addSeconds((int) $rush['buyer_deadline_seconds']))) {
            return $this->step(stage: $this->giveUp($commandBus, $buyer['order_id'], sprintf('Rush buyer %d gave up: ticketing had not held its seats %d seconds after the buyer asked it for the first one, the deadline of a rush buyer set by RUSH_BUYER_DEADLINE_SECONDS.', $buyerNumber, (int) $rush['buyer_deadline_seconds'])), pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: true);
        }
        if ($pendingSeatId !== null) {
            return $this->step(stage: self::PICKING, pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: false);
        }
        $seatIdsForThisBuyer = array_values(array_diff($freeSeatIds, $refusedSeatIds));
        if ($seatIdsForThisBuyer === []) {
            return $this->step(stage: $this->giveUp($commandBus, $buyer['order_id'], sprintf('Rush buyer %d gave up: no free seat of the concert was left.', $buyerNumber)), pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: true);
        }
        $pendingSeatId = $seatIdsForThisBuyer[array_rand($seatIdsForThisBuyer)];
        $freeSeatIds = array_values(array_diff($freeSeatIds, [$pendingSeatId]));
        $commandBus->send(new RequestSeat(orderId: $buyer['order_id'], seatId: $pendingSeatId));

        return $this->step(stage: self::PICKING, pendingSeatId: $pendingSeatId, refusedSeatIds: $refusedSeatIds, acted: true);
    }

    /**
     * @param list<string> $refusedSeatIds
     * @return array{stage: string, pendingSeatId: ?string, refusedSeatIds: list<string>, acted: bool}
     */
    private function step(string $stage, ?string $pendingSeatId, array $refusedSeatIds, bool $acted): array
    {
        return ['stage' => $stage, 'pendingSeatId' => $pendingSeatId, 'refusedSeatIds' => $refusedSeatIds, 'acted' => $acted];
    }

    private function join(Rushes $rushes, CommandBus $commandBus, array $rush, RushBuyerRole $role, int $buyerNumber, string $joinedAt): void
    {
        $customerId = $commandBus->send(new RegisterCustomer(name: 'Rush buyer ' . $buyerNumber, email: $role->emailOf($rush['rush_id'], $buyerNumber)));
        $orderId = $commandBus->send(new StartOrder(customerId: $customerId, concertId: $rush['concert_id']));
        $rushes->addBuyer(
            rushId: $rush['rush_id'],
            buyerNumber: $buyerNumber,
            customerId: $customerId,
            orderId: $orderId,
            concertId: $rush['concert_id'],
            role: $role->value,
            joinedAt: $joinedAt,
        );
    }

    /**
     * @return list<RushBuyerRole>
     */
    private function rolesOf(array $rush): array
    {
        $buyers = (int) $rush['buyers_requested'];
        $shares = [
            RushBuyerRole::DECLINED->value => (int) $rush['declined_share'],
            RushBuyerRole::OUTAGE->value => (int) $rush['outage_share'],
            RushBuyerRole::BOUNCE->value => (int) $rush['bounce_share'],
        ];
        $shares[RushBuyerRole::PAYING->value] = 100 - array_sum($shares);
        $buyersOf = array_map(fn (int $share) => intdiv($buyers * $share, 100), $shares);
        $leftOver = array_map(fn (int $share) => $buyers * $share % 100, $shares);
        arsort($leftOver, SORT_NUMERIC);
        foreach (array_slice(array_keys($leftOver), 0, $buyers - array_sum($buyersOf)) as $role) {
            $buyersOf[$role]++;
        }
        $roles = [];
        foreach ($buyersOf as $role => $count) {
            $roles = [...$roles, ...array_fill(0, $count, RushBuyerRole::from($role))];
        }

        return (new Randomizer(new Mt19937(crc32($rush['rush_id']))))->shuffleArray($roles);
    }

    /**
     * @return list<string>
     */
    private function freeSeatIdsOf(Rushes $rushes, QueryBus $queryBus, string $concertId): array
    {
        $concert = $queryBus->sendWithRouting('concert.detail', $concertId);
        $seatIds = [];
        foreach ($concert['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $seatIds = [...$seatIds, ...array_column($row['seats'], 'seatId')];
            }
        }

        return array_values(array_diff(
            $seatIds,
            $queryBus->sendWithRouting('concert.taken_seats', ['concertId' => $concertId, 'excludingOrderId' => null]),
            $rushes->pendingSeatIdsOf($concertId),
        ));
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
