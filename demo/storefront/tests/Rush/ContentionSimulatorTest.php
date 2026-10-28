<?php

declare(strict_types=1);

namespace Tests\Rush;

use App\Concert\ConcertCatalogue;
use App\Concert\ConcertProjector;
use App\Ordering\RecordSeatRefusal;
use App\Rush\Contentions;
use App\Rush\ContentionSimulator;
use App\Rush\StartContention;
use DomainException;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Gateway\DistributedBus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Ordering\StorefrontFlowTestCase;

/**
 * @internal
 */
final class ContentionSimulatorTest extends StorefrontFlowTestCase
{
    private const CONTENTION_ID = '0199f3a8-6f2a-7c1e-9a51-5b4d3c2a1f00';
    private const SEAT_ID = self::CONCERT_ID . '/A-3';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('contention_buyers')->delete();
        DB::table('contentions')->delete();
    }

    protected function storefrontServices(): array
    {
        return [ContentionSimulator::class, Contentions::class, ConcertProjector::class, ConcertCatalogue::class];
    }

    public function test_a_contention_starts_an_order_for_each_buyer_and_asks_ticketing_to_hold_the_same_seat_with_distinct_hold_ids(): void
    {
        $this->concertOnSale();

        $this->contend(buyers: 5);

        $holds = array_values(array_filter($this->ticketingReceives(), fn (array $request) => $request[0] === 'tickets.hold'));
        self::assertCount(5, $holds);
        self::assertSame(array_fill(0, 5, self::SEAT_ID), array_map(fn (array $hold) => $hold[1]['seatId'], $holds));
        self::assertCount(5, array_unique(array_map(fn (array $hold) => $hold[1]['holdId'], $holds)));
        self::assertCount(5, array_unique(array_map(fn (array $hold) => $hold[1]['orderId'], $holds)));
        self::assertCount(5, array_unique(array_map(fn (array $hold) => $hold[1]['customerId'], $holds)));
        $state = $this->contentionState();
        self::assertSame(['contentionId' => self::CONTENTION_ID, 'concertId' => self::CONCERT_ID, 'seatId' => self::SEAT_ID, 'seatCode' => 'A-3', 'buyersRequested' => 5, 'outcome' => ['headline' => '5 buyers ask ticketing for seat A-3', 'reason' => null]], array_diff_key($state, ['buyers' => true]));
        self::assertSame(['Contending buyer 1', 'Contending buyer 2', 'Contending buyer 3', 'Contending buyer 4', 'Contending buyer 5'], array_column($state['buyers'], 'name'));
        self::assertSame(array_fill(0, 5, 'pending'), array_column($state['buyers'], 'state'));
    }

    public function test_one_buyer_holds_the_seat_and_every_other_one_fails_with_the_reason_ticketing_refused_it(): void
    {
        $this->concertOnSale();
        $this->ticketingService->holdsToRefuse = 4;
        $this->ticketingService->refusalReason = 'Seat A-3 is held by another order';

        $this->contend(buyers: 5);
        $this->ticketingReplies();

        $buyers = $this->contentionState()['buyers'];
        self::assertSame(['refused', 'refused', 'refused', 'refused', 'held'], array_column($buyers, 'state'));
        self::assertSame([...array_fill(0, 4, 'Seat A-3 is held by another order'), null], array_column($buyers, 'reason'));
        $orders = array_map(fn (array $buyer) => $this->order($buyer['orderId']), $buyers);
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'seats_held'], array_column($orders, 'status'));
        self::assertSame([
            'Contending buyer 1 lost seat A-3: ticketing refused to hold it, saying "Seat A-3 is held by another order".',
            'Contending buyer 2 lost seat A-3: ticketing refused to hold it, saying "Seat A-3 is held by another order".',
            'Contending buyer 3 lost seat A-3: ticketing refused to hold it, saying "Seat A-3 is held by another order".',
            'Contending buyer 4 lost seat A-3: ticketing refused to hold it, saying "Seat A-3 is held by another order".',
            null,
        ], array_column($orders, 'failureReason'));
        self::assertSame([['seatId' => self::SEAT_ID, 'expiresAt' => self::EXPIRES_AT]], $orders[4]['heldSeats']);
    }

    public function test_a_contention_on_a_seat_already_held_ends_with_every_buyer_refused_with_the_reason(): void
    {
        $this->concertOnSale();
        $adaOrderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($adaOrderId, self::SEAT_ID);
        $this->ticketingService->holdsToRefuse = 3;
        $this->ticketingService->refusalReason = 'Seat A-3 is held by another order';

        $this->contend(buyers: 3);
        $this->ticketingReplies();

        $buyers = $this->contentionState()['buyers'];
        self::assertSame(['refused', 'refused', 'refused'], array_column($buyers, 'state'));
        self::assertSame(array_fill(0, 3, 'Seat A-3 is held by another order'), array_column($buyers, 'reason'));
        self::assertSame(['failed', 'failed', 'failed'], array_map(fn (array $buyer) => $this->order($buyer['orderId'])['status'], $buyers));
        self::assertSame('seats_held', $this->order($adaOrderId)['status']);
    }

    public function test_a_refusal_of_a_seat_to_a_customer_outside_a_contention_leaves_their_order_open(): void
    {
        $this->concertOnSale();
        $adaOrderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->ticketingService->holdsToRefuse = 1;

        $this->seatIsHeld($adaOrderId, self::SEAT_ID);

        $order = $this->order($adaOrderId);
        self::assertSame('started', $order['status']);
        self::assertSame(['seatId' => self::SEAT_ID, 'reason' => 'someone was faster: seat A-3'], $order['lastRefusal']);
    }

    public function test_a_contention_one_buyer_won_is_headlined_by_the_buyer_holding_the_seat(): void
    {
        $this->concertOnSale();
        $this->ticketingService->holdsToRefuse = 4;

        $this->contend(buyers: 5);
        $this->ticketingReplies();

        self::assertSame(['headline' => 'Contending buyer 5 holds seat A-3; ticketing refused every other buyer', 'reason' => null], $this->contentionState()['outcome']);
    }

    public function test_a_contention_whose_winner_let_the_hold_end_is_headlined_by_the_buyer_that_held_the_seat(): void
    {
        $this->concertOnSale();
        $this->ticketingService->holdsToRefuse = 1;
        $this->contend(buyers: 2);
        $this->ticketingReplies();
        $winner = $this->contentionState()['buyers'][1];

        $this->ticketing->getGateway(DistributedBus::class)->convertAndPublishEvent('tickets.hold_expired', ['orderId' => $winner['orderId'], 'concertId' => self::CONCERT_ID, 'seatId' => self::SEAT_ID, 'customerId' => $winner['customerId'], 'holdId' => $winner['holdId']], metadata: ['concertId' => self::CONCERT_ID]);
        $this->storefrontConsumes();

        self::assertSame(['refused', 'released'], array_column($this->contentionState()['buyers'], 'state'));
        self::assertSame(['headline' => 'Contending buyer 2 held seat A-3 until its hold ended; ticketing refused every other buyer', 'reason' => null], $this->contentionState()['outcome']);
    }

    public function test_a_refusal_delivered_twice_fails_the_buyer_once_and_dead_letters_nothing(): void
    {
        $this->concertOnSale();
        $this->ticketingService->holdsToRefuse = 1;
        $this->ticketingService->refusalReason = 'Seat A-3 is held by another order';
        $this->contend(buyers: 2);
        $this->ticketingReplies();
        $refused = $this->contentionState()['buyers'][0];

        $this->storefront->getCommandBus()->send(new RecordSeatRefusal(orderId: $refused['orderId'], seatId: self::SEAT_ID, holdId: $refused['holdId'], reason: 'Seat A-3 is held by yet another order'));
        $this->storefrontAsyncRuns();

        $order = $this->order($refused['orderId']);
        self::assertSame('failed', $order['status']);
        self::assertSame('Contending buyer 1 lost seat A-3: ticketing refused to hold it, saying "Seat A-3 is held by another order".', $order['failureReason']);
        self::assertSame(['refused', 'held'], array_column($this->contentionState()['buyers'], 'state'));
        self::assertSame('Seat A-3 is held by another order', $this->contentionState()['buyers'][0]['reason']);
        self::assertSame(0, $this->storefront->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('seatsAlreadyTaken')]
    public function test_a_contention_every_buyer_was_refused_is_headlined_by_no_buyer_getting_the_seat_and_why(string $reason, string $headline): void
    {
        $this->concertOnSale();
        $this->ticketingService->holdsToRefuse = 3;
        $this->ticketingService->refusalReason = $reason;

        $this->contend(buyers: 3);
        $this->ticketingReplies();

        self::assertSame(['refused', 'refused', 'refused'], array_column($this->contentionState()['buyers'], 'state'));
        self::assertSame(['headline' => $headline, 'reason' => $reason], $this->contentionState()['outcome']);
    }

    public static function seatsAlreadyTaken(): iterable
    {
        yield 'held by another order' => [
            'Seat A-3 of The Night Owls cannot be held for order 0199f3a8-6f2a-7c1e-9a51-00000000000a, because someone was faster: order 0199f3a8-6f2a-7c1e-9a51-00000000000b holds it until 2026-10-25T12:02:00+00:00. Pick another seat, or try this one again after that hold expires.',
            'No buyer got seat A-3: ticketing refused every request, because the seat was already held',
        ];
        yield 'sold to another order' => [
            'Seat A-3 of The Night Owls cannot be held for order 0199f3a8-6f2a-7c1e-9a51-00000000000a, because someone was faster: it is sold to order 0199f3a8-6f2a-7c1e-9a51-00000000000b. Pick another seat.',
            'No buyer got seat A-3: ticketing refused every request, because the seat was already sold',
        ];
        yield 'any other refusal' => [
            'Seat A-3 of The Night Owls cannot be held for order 0199f3a8-6f2a-7c1e-9a51-00000000000a, because the concert is cancelled, not on sale. Seats are held only while a concert is on sale.',
            'No buyer got seat A-3: ticketing refused every request',
        ];
    }

    #[DataProvider('refusalsOfTicketing')]
    public function test_each_refusal_says_whether_the_boundary_refused_it_during_another_append_or_the_seat_was_already_taken(string $reason, string $refusal, string $refusalExplanation): void
    {
        $this->concertOnSale();
        $this->ticketingService->holdsToRefuse = 1;
        $this->ticketingService->refusalReason = $reason;

        $this->contend(buyers: 2);
        $this->ticketingReplies();

        $buyers = $this->contentionState()['buyers'];
        self::assertSame(['refused', 'held'], array_column($buyers, 'state'));
        self::assertSame([$reason, null], array_column($buyers, 'reason'));
        self::assertSame([$refusal, null], array_column($buyers, 'refusal'));
        self::assertSame([$refusalExplanation, null], array_column($buyers, 'refusalExplanation'));
    }

    public static function refusalsOfTicketing(): iterable
    {
        yield 'lost the race to the append' => [
            sprintf('Seat %s cannot be held for order 0199f3a8-6f2a-7c1e-9a51-00000000000a, because someone was faster: the seat changed each time ticketing tried to hold it. Pick the seat again, or choose another one.', self::SEAT_ID),
            'lost_the_race',
            'Refused by the consistency boundary: another hold of the seat was being appended at the same time.',
        ];
        yield 'seat already held' => [
            'Seat A-3 of The Night Owls cannot be held for order 0199f3a8-6f2a-7c1e-9a51-00000000000a, because someone was faster: order 0199f3a8-6f2a-7c1e-9a51-00000000000b holds it until 2026-10-25T12:02:00+00:00. Pick another seat, or try this one again after that hold expires.',
            'seat_already_held',
            'Refused because the seat was already held.',
        ];
        yield 'seat already sold' => [
            'Seat A-3 of The Night Owls cannot be held for order 0199f3a8-6f2a-7c1e-9a51-00000000000a, because someone was faster: it is sold to order 0199f3a8-6f2a-7c1e-9a51-00000000000b. Pick another seat.',
            'seat_already_sold',
            'Refused because the seat was already sold.',
        ];
        yield 'any other refusal' => [
            'Seat A-3 of The Night Owls cannot be held for order 0199f3a8-6f2a-7c1e-9a51-00000000000a, because the concert is cancelled, not on sale. Seats are held only while a concert is on sale.',
            'other',
            'Refused by ticketing.',
        ];
    }

    #[DataProvider('buyerCountsOutOfBounds')]
    public function test_a_contention_of_fewer_than_2_or_more_than_10_buyers_is_refused(int $buyers): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('A contention of %d buyers cannot start: a contention sends 2 to 10 buyers after one seat. Ask for between 2 and 10 buyers.', $buyers));

        new StartContention(contentionId: self::CONTENTION_ID, concertId: self::CONCERT_ID, seatId: self::SEAT_ID, buyers: $buyers);
    }

    public static function buyerCountsOutOfBounds(): iterable
    {
        yield 'one buyer' => [1];
        yield 'eleven buyers' => [11];
    }

    public function test_a_contention_for_a_seat_the_concert_does_not_have_is_refused_naming_the_seat(): void
    {
        $this->concertOnSale();

        try {
            $this->storefront->getCommandBus()->send(new StartContention(contentionId: self::CONTENTION_ID, concertId: self::CONCERT_ID, seatId: self::CONCERT_ID . '/Z-99', buyers: 5));
            self::fail('The contention started');
        } catch (DomainException $refusal) {
            self::assertSame(sprintf('Concert %s has no seat %s/Z-99, so a contention cannot send buyers after it. Pick one of the seats on the concert page.', self::CONCERT_ID, self::CONCERT_ID), $refusal->getMessage());
        }
        self::assertNull($this->contentionState());
        self::assertSame([], $this->ticketingReceives());
    }

    public function test_a_contention_on_a_concert_not_on_sale_is_refused_naming_its_status(): void
    {
        $this->concertOnSale(opened: false);

        try {
            $this->contend(buyers: 5);
            self::fail('The contention started');
        } catch (DomainException $refusal) {
            self::assertSame(sprintf('Concert %s is scheduled, not on sale, so a contention cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing, or pick a concert listed as on_sale on /concerts.', self::CONCERT_ID), $refusal->getMessage());
        }
        self::assertSame([], $this->ticketingReceives());
    }

    public function test_an_unknown_contention_has_no_state(): void
    {
        self::assertNull($this->storefront->sendQueryWithRouting('contention.state', '0199f3a8-6f2a-7c1e-9a51-000000000000'));
        self::assertNull($this->storefront->sendQueryWithRouting('contention.state', 'not-a-contention-id'));
    }

    private function contend(int $buyers): void
    {
        $this->storefront->getCommandBus()->send(new StartContention(contentionId: self::CONTENTION_ID, concertId: self::CONCERT_ID, seatId: self::SEAT_ID, buyers: $buyers));
    }

    private function contentionState(): ?array
    {
        return $this->storefront->sendQueryWithRouting('contention.state', self::CONTENTION_ID);
    }

    private function concertOnSale(bool $opened = true): void
    {
        $distributedBus = $this->ticketing->getGateway(DistributedBus::class);
        $distributedBus->convertAndPublishEvent('concert.scheduled', [
            'concertId' => self::CONCERT_ID,
            'version' => 1,
            'venueName' => 'Ecotone Hall',
            'artist' => 'The Night Owls',
            'startsAt' => '2026-12-01T20:00:00+00:00',
            'currency' => 'EUR',
            'tiers' => ['standard' => 4500, 'vip' => 12000],
            'seats' => array_map(fn (int $number) => ['seatId' => self::CONCERT_ID . '/A-' . $number, 'seatCode' => 'A-' . $number, 'tier' => 'standard'], range(1, 6)),
        ], metadata: ['concertId' => self::CONCERT_ID]);
        if ($opened) {
            $distributedBus->convertAndPublishEvent('concert.sales_opened', ['concertId' => self::CONCERT_ID], metadata: ['concertId' => self::CONCERT_ID]);
        }
        $this->storefrontConsumes();
    }
}
