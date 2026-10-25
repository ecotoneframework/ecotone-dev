<?php

declare(strict_types=1);

namespace Tests\Rush;

use App\Concert\ConcertCatalogue;
use App\Concert\ConcertProjector;
use App\Ordering\MarkSeatHeld;
use App\Ordering\TicketingReplies;
use App\Payment\Checkout;
use App\Payment\PaymentAttempts;
use App\Payment\PaymentProvider;
use App\Rush\RushSimulator;
use App\Rush\Rushes;
use App\Rush\StartRush;
use DomainException;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Ordering\StorefrontFlowTestCase;

/**
 * @internal
 */
final class RushSimulatorTest extends StorefrontFlowTestCase
{
    private const RUSH_ID = '0199f3a8-6f2a-7c1e-9a51-7a3c5e9b2d10';

    private string $joinFailsOnce;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('rush_buyers')->delete();
        DB::table('rushes')->delete();
        DB::table('payment_attempts')->delete();
        Carbon::setTestNow('2026-10-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function storefrontServices(): array
    {
        $joinFailsOnce = new class () {
            public bool $armed = false;

            #[Around(pointcut: RushSimulator::class . '::join')]
            public function failAfterJoining(MethodInvocation $invocation): mixed
            {
                $result = $invocation->proceed();
                if ($this->armed) {
                    $this->armed = false;

                    throw new RuntimeException('the buyer could not join');
                }

                return $result;
            }
        };
        $this->joinFailsOnce = $joinFailsOnce::class;

        return [RushSimulator::class, Rushes::class, ConcertProjector::class, ConcertCatalogue::class, TicketingReplies::class, Checkout::class, PaymentProvider::class, PaymentAttempts::class, $joinFailsOnce::class];
    }

    public function test_a_rush_registers_its_buyers_and_starts_an_order_for_each_of_them(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 5, seatsPerBuyer: 1);
        $this->rushRuns();

        $buyers = $this->buyers();
        self::assertCount(5, $buyers);
        self::assertSame(['Rush buyer 1', 'Rush buyer 2', 'Rush buyer 3', 'Rush buyer 4', 'Rush buyer 5'], array_map(fn (array $buyer) => $this->storefront->sendQueryWithRouting('customer.detail', $buyer['customerId'])['name'], $buyers));
        self::assertCount(5, array_unique(array_column($buyers, 'orderId')));
        foreach ($buyers as $buyer) {
            self::assertSame(self::CONCERT_ID, $this->order($buyer['orderId'])['concertId']);
            self::assertSame($buyer['customerId'], $this->order($buyer['orderId'])['customerId']);
        }
    }

    public function test_rush_buyers_are_spread_over_a_few_seconds_rather_than_joining_at_once(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 10, seatsPerBuyer: 1);
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1000, executionTimeLimitInMilliseconds: 2000));

        self::assertSame(1, $this->rushState()['created']);

        $this->rushRuns();

        self::assertSame(10, $this->rushState()['created']);
    }

    public function test_rush_buyers_hold_only_free_seats_and_leave_the_orders_of_real_customers_alone(): void
    {
        $this->concertOnSale(seats: 6);
        $adaOrderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $adaOrderId, seatId: self::CONCERT_ID . '/A-1', expiresAt: self::EXPIRES_AT));

        $this->startRush(buyers: 5, seatsPerBuyer: 1);
        $this->rushRuns();

        $holds = array_values(array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
        self::assertCount(5, $holds);
        self::assertEqualsCanonicalizing(
            array_map(fn (int $number) => self::CONCERT_ID . '/A-' . $number, range(2, 6)),
            array_map(fn (array $hold) => $hold[1]['seatId'], $holds),
        );
        self::assertSame([], array_filter($this->ticketingService->received, fn (array $request) => $request[1]['orderId'] === $adaOrderId));
        $adaOrder = $this->order($adaOrderId);
        self::assertSame('seats_held', $adaOrder['status']);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-1', 'expiresAt' => self::EXPIRES_AT]], $adaOrder['heldSeats']);
    }

    public function test_a_buyer_holds_its_seats_one_after_another_and_pays_once_all_are_held(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 1, seatsPerBuyer: 2);
        $this->rushRuns();

        $orderId = $this->buyers()[0]['orderId'];
        $order = $this->order($orderId);
        self::assertSame('paid', $order['status']);
        self::assertCount(2, $order['heldSeats']);
        self::assertSame(['tickets.hold', 'tickets.hold', 'tickets.confirm'], array_column($this->ticketingService->received, 0));
        self::assertSame('charged', $this->storefront->sendQueryWithRouting('payment.status', $order['paymentReference']));
    }

    public function test_the_buyers_pay_with_cards_in_the_shares_of_the_rush(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 10, seatsPerBuyer: 1, declinedShare: 30, outageShare: 10, bounceShare: 10);
        $this->rushRuns();

        $state = $this->rushState();
        self::assertSame(10, $state['created']);
        self::assertSame(10, $state['held']);
        self::assertSame(6, $state['paid']);
        self::assertSame(3, $state['failed']);
        self::assertSame(1, $state['paying']);
        self::assertSame(0, $state['gaveUp']);
        self::assertNotNull($state['finishedAt']);
        self::assertSame(1, $this->storefront->getGateway(DeadLetterGateway::class)->count());
        $failureReasons = array_filter(array_map(fn (array $buyer) => $this->order($buyer['orderId'])['failureReason'], $this->buyers()));
        self::assertSame(array_fill(0, 3, 'The card ending 0000 was declined, so the order is not paid and its seats are released. Pick the seats again and pay with another card.'), array_values($failureReasons));
        $emails = array_map(fn (array $buyer) => $this->storefront->sendQueryWithRouting('customer.detail', $buyer['customerId'])['email'], $this->buyers());
        self::assertCount(1, array_filter($emails, fn (string $email) => str_ends_with($email, '@bounce.example')));
        self::assertCount(9, array_filter($emails, fn (string $email) => str_ends_with($email, '@example.com')));
    }

    public function test_a_refused_seat_is_tried_again_on_another_free_seat(): void
    {
        $this->concertOnSale(seats: 12);
        $this->ticketingService->holdsToRefuse = 1;

        $this->startRush(buyers: 1, seatsPerBuyer: 1);
        $this->rushRuns();

        $holds = array_values(array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
        self::assertCount(2, $holds);
        self::assertNotSame($holds[0][1]['seatId'], $holds[1][1]['seatId']);
        self::assertSame('paid', $this->order($this->buyers()[0]['orderId'])['status']);
    }

    public function test_a_buyer_refused_three_times_gives_up_and_lets_its_order_go(): void
    {
        $this->concertOnSale(seats: 12);
        $this->ticketingService->holdsToRefuse = 3;

        $this->startRush(buyers: 1, seatsPerBuyer: 1);
        $this->rushRuns();

        self::assertCount(3, array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
        $order = $this->order($this->buyers()[0]['orderId']);
        self::assertSame('failed', $order['status']);
        self::assertSame('Rush buyer 1 gave up after ticketing refused 3 seats.', $order['failureReason']);
        $state = $this->rushState();
        self::assertSame(1, $state['gaveUp']);
        self::assertSame(0, $state['failed']);
        self::assertNotNull($state['finishedAt']);
    }

    public function test_a_buyer_holding_some_seats_gives_up_releasing_them_in_ticketing(): void
    {
        $this->concertOnSale(seats: 12);
        $this->startRush(buyers: 1, seatsPerBuyer: 2);
        $this->rushRuns(rounds: 1);
        $this->ticketingService->holdsToRefuse = 3;

        $this->rushRuns();

        $order = $this->order($this->buyers()[0]['orderId']);
        self::assertSame('failed', $order['status']);
        $releases = array_values(array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.release'));
        self::assertCount(1, $releases);
        self::assertSame($order['heldSeats'][0]['seatId'], $releases[0][1]['seatId']);
    }

    public function test_a_buyer_finding_no_free_seat_gives_up(): void
    {
        $this->concertOnSale(seats: 2);

        $this->startRush(buyers: 3, seatsPerBuyer: 1);
        $this->rushRuns();

        $state = $this->rushState();
        self::assertSame(3, $state['created']);
        self::assertSame(2, $state['paid']);
        self::assertSame(1, $state['gaveUp']);
        self::assertCount(2, array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
    }

    public function test_a_rush_started_twice_creates_its_buyers_once(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 3, seatsPerBuyer: 1);
        $this->startRush(buyers: 3, seatsPerBuyer: 1);
        $this->rushRuns();

        self::assertCount(3, $this->buyers());
        self::assertSame(3, $this->rushState()['created']);
        self::assertSame(3, $this->rushState()['paid']);
    }

    public function test_a_buyer_dead_lettered_while_joining_is_replayed_without_a_second_customer_or_order(): void
    {
        $this->concertOnSale(seats: 12);
        $this->app->make($this->joinFailsOnce)->armed = true;

        $this->startRush(buyers: 3, seatsPerBuyer: 1);
        $this->rushRuns();

        $deadLetter = $this->storefront->getGateway(DeadLetterGateway::class);
        self::assertSame(1, $deadLetter->count());
        self::assertSame(2, $this->rushState()['created']);

        $deadLetter->replayAll();
        $this->rushRuns();

        self::assertSame(0, $deadLetter->count());
        $buyers = $this->buyers();
        self::assertSame([1, 2, 3], array_column($buyers, 'buyerNumber'));
        self::assertCount(3, array_unique(array_column($buyers, 'customerId')));
        self::assertSame(3, $this->rushState()['paid']);
        self::assertCount(3, array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
    }

    public function test_the_rush_state_reports_its_concert_and_the_buyers_it_was_asked_for_before_any_joined(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 4, seatsPerBuyer: 2);
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertSame(
            ['rushId' => self::RUSH_ID, 'concertId' => self::CONCERT_ID, 'buyersRequested' => 4, 'seatsPerBuyer' => 2, 'created' => 0, 'held' => 0, 'paying' => 0, 'paid' => 0, 'failed' => 0, 'gaveUp' => 0, 'startedAt' => '2026-10-25T12:00:00+00:00', 'finishedAt' => null],
            $this->rushState(),
        );
    }

    public function test_the_state_of_a_rush_never_started_is_null(): void
    {
        self::assertNull($this->rushState());
    }

    public function test_rush_buyers_are_not_listed_among_the_customers_to_shop_as(): void
    {
        $this->concertOnSale(seats: 12);
        $this->startOrderOf('Ada Lovelace', 'ada@example.com');

        $this->startRush(buyers: 2, seatsPerBuyer: 1);
        $this->rushRuns();

        self::assertSame(['Ada Lovelace'], array_column($this->storefront->sendQueryWithRouting('customers.list'), 'name'));
    }

    public function test_a_rush_on_a_concert_that_is_not_on_sale_is_refused_naming_its_status(): void
    {
        $this->concertOnSale(seats: 12, opened: false);
        $this->startRush(buyers: 3, seatsPerBuyer: 1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Concert %s is scheduled, not on sale, so rush %s cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing and start a new rush, or start the rush on a concert listed as on_sale on /concerts.', self::CONCERT_ID, self::RUSH_ID));

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
    }

    #[DataProvider('rushesThatCannotRun')]
    public function test_a_rush_that_cannot_run_is_refused_naming_the_way_out(array $arguments, string $message): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);

        new StartRush(...['rushId' => self::RUSH_ID, 'concertId' => self::CONCERT_ID, 'buyers' => 30, 'declinedShare' => 30, 'outageShare' => 10, 'bounceShare' => 10, 'seatsPerBuyer' => 2, ...$arguments]);
    }

    public static function rushesThatCannotRun(): iterable
    {
        yield 'no buyers' => [['buyers' => 0], 'A rush of 0 buyers cannot run: a rush sends 1 to 200 buyers. Ask for between 1 and 200 buyers.'];
        yield 'too many buyers' => [['buyers' => 201], 'A rush of 201 buyers cannot run: a rush sends 1 to 200 buyers. Ask for between 1 and 200 buyers.'];
        yield 'no seats per buyer' => [['seatsPerBuyer' => 0], 'A rush buyer cannot hold 0 seats: ticketing lets a customer hold 1 to 4 seats per concert. Ask for between 1 and 4 seats per buyer.'];
        yield 'more seats than a customer may hold' => [['seatsPerBuyer' => 5], 'A rush buyer cannot hold 5 seats: ticketing lets a customer hold 1 to 4 seats per concert. Ask for between 1 and 4 seats per buyer.'];
        yield 'a negative share' => [['outageShare' => -10], 'The outage share of a rush is -10 %, but a share is 0 to 100 %. Give the declined, outage and bounce shares as whole percents between 0 and 100.'];
        yield 'shares above 100 %' => [['declinedShare' => 60, 'outageShare' => 30, 'bounceShare' => 20], 'The declined (60 %), outage (30 %) and bounce (20 %) shares of a rush add up to 110 %, but each buyer plays one part, so they cannot exceed 100 %. Lower them until they add up to at most 100 %; the rest of the buyers pay with a card that succeeds.'];
    }

    private function startRush(int $buyers, int $seatsPerBuyer, int $declinedShare = 0, int $outageShare = 0, int $bounceShare = 0): void
    {
        $this->storefront->getCommandBus()->send(new StartRush(
            rushId: self::RUSH_ID,
            concertId: self::CONCERT_ID,
            buyers: $buyers,
            declinedShare: $declinedShare,
            outageShare: $outageShare,
            bounceShare: $bounceShare,
            seatsPerBuyer: $seatsPerBuyer,
        ));
    }

    private function rushRuns(int $rounds = 20): void
    {
        for ($round = 0; $round < $rounds; $round++) {
            $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1000, executionTimeLimitInMilliseconds: 2000, stopOnError: false));
            $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
            $this->storefrontConsumes();
            Carbon::setTestNow(Carbon::now()->addSeconds(2));
        }
    }

    private function rushState(): ?array
    {
        return $this->storefront->sendQueryWithRouting('rush.state', self::RUSH_ID);
    }

    private function buyers(): array
    {
        return $this->storefront->sendQueryWithRouting('rush.buyers', self::RUSH_ID);
    }

    private function concertOnSale(int $seats, bool $opened = true): void
    {
        $distributedBus = $this->ticketing->getGateway(DistributedBus::class);
        $distributedBus->convertAndPublishEvent('concert.scheduled', [
            'concertId' => self::CONCERT_ID,
            'venueName' => 'Ecotone Hall',
            'artist' => 'The Night Owls',
            'startsAt' => '2026-12-01T20:00:00+00:00',
            'currency' => 'EUR',
            'tiers' => ['standard' => 4500, 'vip' => 12000],
            'seats' => array_map(fn (int $number) => ['seatId' => self::CONCERT_ID . '/A-' . $number, 'seatCode' => 'A-' . $number, 'tier' => 'standard'], range(1, $seats)),
        ], metadata: ['concertId' => self::CONCERT_ID]);
        if ($opened) {
            $distributedBus->convertAndPublishEvent('concert.sales_opened', ['concertId' => self::CONCERT_ID], metadata: ['concertId' => self::CONCERT_ID]);
        }
        $this->storefrontConsumes();
    }
}
