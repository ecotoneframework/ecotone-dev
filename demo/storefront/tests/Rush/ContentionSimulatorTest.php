<?php

declare(strict_types=1);

namespace Tests\Rush;

use App\Concert\ConcertCatalogue;
use App\Concert\ConcertProjector;
use App\Rush\Contentions;
use App\Rush\ContentionSimulator;
use App\Rush\StartContention;
use DomainException;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
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

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $holds = array_values(array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
        self::assertCount(5, $holds);
        self::assertSame(array_fill(0, 5, self::SEAT_ID), array_map(fn (array $hold) => $hold[1]['seatId'], $holds));
        self::assertCount(5, array_unique(array_map(fn (array $hold) => $hold[1]['holdId'], $holds)));
        self::assertCount(5, array_unique(array_map(fn (array $hold) => $hold[1]['orderId'], $holds)));
        self::assertCount(5, array_unique(array_map(fn (array $hold) => $hold[1]['customerId'], $holds)));
        $state = $this->contentionState();
        self::assertSame(['contentionId' => self::CONTENTION_ID, 'concertId' => self::CONCERT_ID, 'seatId' => self::SEAT_ID, 'seatCode' => 'A-3', 'buyersRequested' => 5], array_diff_key($state, ['buyers' => true]));
        self::assertSame(['Contending buyer 1', 'Contending buyer 2', 'Contending buyer 3', 'Contending buyer 4', 'Contending buyer 5'], array_column($state['buyers'], 'name'));
        self::assertSame(array_fill(0, 5, 'pending'), array_column($state['buyers'], 'state'));
    }

    public function test_one_buyer_holds_the_seat_and_every_other_one_fails_with_the_reason_ticketing_refused_it(): void
    {
        $this->concertOnSale();
        $this->ticketingService->holdsToRefuse = 4;
        $this->ticketingService->refusalReason = 'Seat A-3 is held by another order';

        $this->contend(buyers: 5);
        $this->contentionRuns();

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
        $this->contentionRuns();

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

    private function contentionRuns(): void
    {
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $this->storefront->run('storefront', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
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
