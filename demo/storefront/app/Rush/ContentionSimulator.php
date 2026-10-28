<?php

declare(strict_types=1);

namespace App\Rush;

use App\Concert\SeatCode;
use App\Customer\RegisterCustomer;
use App\Ordering\FailOrder;
use App\Ordering\RequestSeat;
use App\Ordering\SeatRefused;
use App\Ordering\StartOrder;
use DomainException;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Uuid;

final class ContentionSimulator
{
    #[CommandHandler]
    public function start(StartContention $command, #[Reference] Contentions $contentions, #[Reference] QueryBus $queryBus, #[Reference] CommandBus $commandBus): void
    {
        $concert = $queryBus->sendWithRouting('concert.detail', $command->concertId);
        $refusal = self::refusalOf($concert, $command->concertId, $command->seatId);
        if ($refusal !== null) {
            throw $refusal;
        }
        $contentions->start(contentionId: $command->contentionId, concertId: $command->concertId, seatId: $command->seatId, buyersRequested: $command->buyers, startedAt: Carbon::now()->toAtomString());
        for ($buyerNumber = 1; $buyerNumber <= $command->buyers; $buyerNumber++) {
            $customerId = $commandBus->send(new RegisterCustomer(name: 'Contending buyer ' . $buyerNumber, email: sprintf('contend-%d-%s@example.com', $buyerNumber, substr($command->contentionId, -8))));
            $orderId = $commandBus->send(new StartOrder(customerId: $customerId, concertId: $command->concertId));
            $holdId = Uuid::v7()->toRfc4122();
            $contentions->addBuyer(contentionId: $command->contentionId, buyerNumber: $buyerNumber, customerId: $customerId, orderId: $orderId, holdId: $holdId);
            $commandBus->send(new RequestSeat(orderId: $orderId, seatId: $command->seatId, holdId: $holdId));
        }
    }

    public static function refusalOf(?array $concert, string $concertId, string $seatId): ?DomainException
    {
        if ($concert === null) {
            return new DomainException(sprintf('There is no concert %s in the storefront catalogue, so a contention cannot send buyers to it. Pick a concert listed on /concerts.', $concertId));
        }
        if ($concert['status'] !== 'on_sale') {
            return new DomainException(sprintf('Concert %s is %s, not on sale, so a contention cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing, or pick a concert listed as on_sale on /concerts.', $concertId, $concert['status']));
        }
        foreach ($concert['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                if (in_array($seatId, array_column($row['seats'], 'seatId'), true)) {
                    return null;
                }
            }
        }

        return new DomainException(sprintf('Concert %s has no seat %s, so a contention cannot send buyers after it. Pick one of the seats on the concert page.', $concertId, $seatId));
    }

    #[EventHandler]
    public function failRefusedBuyer(SeatRefused $event, #[Reference] Contentions $contentions, #[Reference] CommandBus $commandBus): void
    {
        $buyerNumber = $contentions->buyerNumberOfOrder($event->orderId);
        if ($buyerNumber === false) {
            return;
        }
        $commandBus->send(new FailOrder(orderId: $event->orderId, reason: sprintf('Contending buyer %d lost seat %s: ticketing refused to hold it, saying "%s".', $buyerNumber, SeatCode::ofSeatId($event->seatId), $event->reason)));
    }

    #[QueryHandler('contention.state')]
    public function state(string $contentionId, #[Reference] Contentions $contentions): ?array
    {
        $contention = Str::isUuid($contentionId) ? $contentions->find($contentionId) : false;
        if ($contention === false) {
            return null;
        }
        $seatId = $contention['seat_id'];

        return [
            'contentionId' => $contention['contention_id'],
            'concertId' => $contention['concert_id'],
            'seatId' => $seatId,
            'seatCode' => SeatCode::ofSeatId($seatId),
            'buyersRequested' => (int) $contention['buyers_requested'],
            'buyers' => array_map(fn (array $buyer) => [
                'buyerNumber' => (int) $buyer['buyer_number'],
                'name' => $buyer['name'],
                'customerId' => $buyer['customer_id'],
                'orderId' => $buyer['order_id'],
                'holdId' => $buyer['hold_id'],
                ...$this->stateOf($buyer, $seatId),
            ], $contentions->buyersOf($contentionId)),
        ];
    }

    /**
     * @return array{state: string, reason: ?string, refusal: ?string, refusalExplanation: ?string}
     */
    private function stateOf(array $buyer, string $seatId): array
    {
        $lastRefusal = $buyer['last_refusal'] === null ? null : json_decode($buyer['last_refusal'], true, flags: JSON_THROW_ON_ERROR);
        if (($lastRefusal['seatId'] ?? null) === $seatId) {
            $refusal = ContentionRefusal::ofReason($lastRefusal['reason']);

            return ['state' => 'refused', 'reason' => $lastRefusal['reason'], 'refusal' => $refusal->value, 'refusalExplanation' => $refusal->explanation()];
        }
        foreach (json_decode($buyer['held_seats'], true, flags: JSON_THROW_ON_ERROR) as $heldSeat) {
            if ($heldSeat['seatId'] === $seatId && $heldSeat['holdId'] === $buyer['hold_id']) {
                return ['state' => 'held', 'reason' => null, 'refusal' => null, 'refusalExplanation' => null];
            }
        }
        if ((json_decode($buyer['requested_holds'], true, flags: JSON_THROW_ON_ERROR)[$seatId] ?? null) === $buyer['hold_id']) {
            return ['state' => 'pending', 'reason' => null, 'refusal' => null, 'refusalExplanation' => null];
        }

        return ['state' => 'released', 'reason' => null, 'refusal' => null, 'refusalExplanation' => null];
    }
}
