<?php

declare(strict_types=1);

namespace Tests\Rush;

use App\Concert\ConcertCatalogue;
use App\Concert\ConcertProjector;
use App\Ordering\MarkTicketsIssued;
use App\Ordering\TicketingReplies;
use App\Payment\Checkout;
use App\Payment\PaymentAttempts;
use App\Payment\PaymentProvider;
use App\Rush\Rushes;
use App\Rush\RushSimulator;
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

    private string $tickFailsOnce;

    private string $rushMessages;

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
        $tickFailsOnce = new class () {
            public bool $armed = false;

            #[Around(pointcut: RushSimulator::class . '::advance')]
            public function failAfterAdvancing(MethodInvocation $invocation): mixed
            {
                $result = $invocation->proceed();
                if ($this->armed) {
                    $this->armed = false;

                    throw new RuntimeException('the rush could not advance');
                }

                return $result;
            }
        };
        $this->tickFailsOnce = $tickFailsOnce::class;
        $rushMessages = new class () {
            public int $handled = 0;

            #[Around(pointcut: RushSimulator::class)]
            public function count(MethodInvocation $invocation): mixed
            {
                $this->handled++;

                return $invocation->proceed();
            }
        };
        $this->rushMessages = $rushMessages::class;

        return [RushSimulator::class, Rushes::class, ConcertProjector::class, ConcertCatalogue::class, TicketingReplies::class, Checkout::class, PaymentProvider::class, PaymentAttempts::class, $tickFailsOnce::class, $rushMessages::class];
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

    public function test_rush_buyers_join_a_few_at_a_time_rather_than_all_at_once(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 50, seatsPerBuyer: 1);
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(RushSimulator::JOINS_PER_TICK, $this->rushState()['created']);

        $this->rushRuns();

        self::assertSame(50, $this->rushState()['created']);
    }

    public function test_rush_buyers_hold_only_free_seats_and_leave_the_orders_of_real_customers_alone(): void
    {
        $this->concertOnSale(seats: 6);
        $adaOrderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($adaOrderId, self::CONCERT_ID . '/A-1');

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

    public function test_the_buyers_choose_payment_outcomes_in_the_shares_of_the_rush(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 10, seatsPerBuyer: 1, declinedShare: 30, outageShare: 10, bounceShare: 10);
        $this->rushRuns();

        $state = $this->rushState();
        self::assertSame(10, $state['created']);
        self::assertSame(0, $state['held']);
        self::assertSame(6, $state['paid']);
        self::assertSame(3, $state['failed']);
        self::assertSame(1, $state['paying']);
        self::assertSame(0, $state['gaveUp']);
        self::assertNotNull($state['finishedAt']);
        self::assertSame(1, $this->storefront->getGateway(DeadLetterGateway::class)->count());
        $failureReasons = array_filter(array_map(fn (array $buyer) => $this->order($buyer['orderId'])['failureReason'], $this->buyers()));
        self::assertSame(array_fill(0, 3, 'The card was declined, so the order is not paid and its seats are released. Pick the seats again and pay with another card.'), array_values($failureReasons));
        $emails = array_map(fn (array $buyer) => $this->storefront->sendQueryWithRouting('customer.detail', $buyer['customerId'])['email'], $this->buyers());
        self::assertCount(1, array_filter($emails, fn (string $email) => str_ends_with($email, '@bounce.example')));
        self::assertCount(9, array_filter($emails, fn (string $email) => str_ends_with($email, '@example.com')));
    }

    public function test_the_rush_state_counts_each_buyer_once_at_the_stage_its_order_has_reached(): void
    {
        $this->concertOnSale(seats: 12);
        $this->startRush(buyers: 1, seatsPerBuyer: 2);

        $this->rushRuns(rounds: 2);

        self::assertSame(['held' => 1, 'paying' => 0, 'paid' => 0, 'issued' => 0, 'failed' => 0, 'gaveUp' => 0], $this->stageCounts());

        $this->rushRuns();

        self::assertSame(['held' => 0, 'paying' => 0, 'paid' => 1, 'issued' => 0, 'failed' => 0, 'gaveUp' => 0], $this->stageCounts());

        $order = $this->order($this->buyers()[0]['orderId']);
        $this->storefront->getCommandBus()->send(new MarkTicketsIssued(orderId: $order['orderId'], tickets: array_map(fn (array $seat) => ['ticketNumber' => 'T-' . $seat['seatId'], 'seatId' => $seat['seatId'], 'tier' => 'standard', 'entryCode' => 'ENTRY-1'], $order['heldSeats'])));

        self::assertSame(['held' => 0, 'paying' => 0, 'paid' => 0, 'issued' => 1, 'failed' => 0, 'gaveUp' => 0], $this->stageCounts());
    }

    public function test_the_buyers_left_over_by_rounding_the_shares_go_to_the_shares_closest_to_another_buyer(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 2, seatsPerBuyer: 1, declinedShare: 25, outageShare: 35);
        $this->rushRuns();

        $state = $this->rushState();
        self::assertSame(0, $state['failed']);
        self::assertSame(1, $state['paid']);
        self::assertSame(1, $state['paying']);
        self::assertSame(1, $this->storefront->getGateway(DeadLetterGateway::class)->count());
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
        $this->rushRuns(rounds: 2);
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

    public function test_a_rush_tick_dead_lettered_while_buyers_join_is_replayed_without_a_second_customer_or_order(): void
    {
        $this->concertOnSale(seats: 12);
        $this->app->make($this->tickFailsOnce)->armed = true;

        $this->startRush(buyers: 3, seatsPerBuyer: 1);
        $this->rushRuns();

        $deadLetter = $this->storefront->getGateway(DeadLetterGateway::class);
        self::assertSame(1, $deadLetter->count());
        self::assertSame(0, $this->rushState()['created']);

        $deadLetter->replayAll();
        $this->rushRuns();

        self::assertSame(0, $deadLetter->count());
        $buyers = $this->buyers();
        self::assertSame([1, 2, 3], array_column($buyers, 'buyerNumber'));
        self::assertCount(3, array_unique(array_column($buyers, 'customerId')));
        self::assertSame(3, $this->rushState()['paid']);
        self::assertCount(3, array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
    }

    public function test_a_rush_of_200_buyers_handles_well_under_one_message_of_its_own_per_second_while_ticketing_is_slow(): void
    {
        $this->concertOnSale(seats: 450);

        $this->startRush(buyers: 200, seatsPerBuyer: 2);
        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 60);

        self::assertLessThanOrEqual(30, $this->rushMessagesHandled());
        self::assertSame(200, $this->rushState()['created']);
    }

    public function test_a_rush_whose_every_buyer_waits_on_ticketing_advances_every_five_seconds_instead_of_every_two(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 3, seatsPerBuyer: 1);
        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 60);

        self::assertSame(14, $this->rushMessagesHandled());
    }

    public function test_a_buyer_whose_seats_ticketing_has_not_held_two_minutes_after_it_asked_for_the_first_gives_up_and_its_order_fails(): void
    {
        $this->concertOnSale(seats: 12);
        $this->startRush(buyers: 1, seatsPerBuyer: 1);

        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 119);

        self::assertSame('started', $this->order($this->buyers()[0]['orderId'])['status']);

        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 10);

        $order = $this->order($this->buyers()[0]['orderId']);
        self::assertSame('failed', $order['status']);
        self::assertSame('Rush buyer 1 gave up: ticketing had not held its seats 120 seconds after the buyer asked it for the first one, the deadline of a rush buyer set by RUSH_BUYER_DEADLINE_SECONDS.', $order['failureReason']);
        self::assertNotNull($this->rushState()['finishedAt']);
    }

    public function test_every_buyer_of_a_rush_ticketing_never_answers_ends_failed_naming_the_deadline_and_the_rush_finishes(): void
    {
        $this->concertOnSale(seats: 12);
        $this->startRush(buyers: 5, seatsPerBuyer: 2);

        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 130);
        $handledWhenFinished = $this->rushMessagesHandled();
        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 60);

        self::assertSame($handledWhenFinished, $this->rushMessagesHandled());
        foreach ($this->buyers() as $buyer) {
            $order = $this->order($buyer['orderId']);
            self::assertSame('failed', $order['status']);
            self::assertSame(sprintf('Rush buyer %d gave up: ticketing had not held its seats 120 seconds after the buyer asked it for the first one, the deadline of a rush buyer set by RUSH_BUYER_DEADLINE_SECONDS.', $buyer['buyerNumber']), $order['failureReason']);
        }
        $state = $this->rushState();
        self::assertSame(5, $state['gaveUp']);
        self::assertNotNull($state['finishedAt']);
    }

    #[DataProvider('secondsBetweenTicks')]
    public function test_a_buyer_of_a_rush_larger_than_its_steps_per_tick_is_never_failed_for_ticketing_before_it_asked_ticketing_for_a_seat(int $secondsBetweenTicks): void
    {
        config(['demo.rush_buyer_deadline_seconds' => 30]);
        $this->concertOnSale(seats: 300);

        $this->startRush(buyers: 60, seatsPerBuyer: 4);
        $this->rushRuns(rounds: 40, secondsBetweenRounds: $secondsBetweenTicks);

        $buyerDeadlineFailures = array_filter(
            array_map(fn (array $buyer) => $this->order($buyer['orderId'])['failureReason'], $this->buyers()),
            fn (?string $reason) => $reason !== null && str_contains($reason, 'RUSH_BUYER_DEADLINE_SECONDS'),
        );
        self::assertSame([], $buyerDeadlineFailures);
        self::assertSame(60, $this->rushState()['paid']);
    }

    #[DataProvider('secondsBetweenTicks')]
    public function test_every_buyer_of_a_rush_larger_than_its_steps_per_tick_ticketing_never_answers_asks_for_a_seat_before_any_buyer_deadline_fires(int $secondsBetweenTicks): void
    {
        config(['demo.rush_buyer_deadline_seconds' => 30]);
        $this->concertOnSale(seats: 300);
        $this->startRush(buyers: 60, seatsPerBuyer: 4);

        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 29, secondsBetweenRounds: $secondsBetweenTicks);
        $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(0, $this->rushState()['gaveUp']);
        self::assertCount(60, array_unique(array_map(fn (array $hold) => $hold[1]['orderId'], $this->holdsReceived())));

        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 90, secondsBetweenRounds: $secondsBetweenTicks);
        $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertCount(60, $this->holdsReceived());
        foreach ($this->buyers() as $buyer) {
            self::assertSame(sprintf('Rush buyer %d gave up: ticketing had not held its seats 30 seconds after the buyer asked it for the first one, the deadline of a rush buyer set by RUSH_BUYER_DEADLINE_SECONDS.', $buyer['buyerNumber']), $this->order($buyer['orderId'])['failureReason']);
        }
        self::assertNotNull($this->rushState()['finishedAt']);
    }

    public static function secondsBetweenTicks(): iterable
    {
        yield 'ticks 2 seconds apart' => [2];
        yield 'ticks 3 seconds apart' => [3];
    }

    public function test_a_rush_past_its_deadline_lets_every_buyer_still_picking_go_and_ends_on_its_own(): void
    {
        config(['demo.rush_deadline_seconds' => 30]);
        $this->concertOnSale(seats: 12);
        $this->startRush(buyers: 3, seatsPerBuyer: 1);

        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 29);

        self::assertNull($this->rushState()['finishedAt']);

        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 10);
        $handledWhenFinished = $this->rushMessagesHandled();
        $this->rushRunsWithoutTicketingAnsweringFor(seconds: 60);

        self::assertSame($handledWhenFinished, $this->rushMessagesHandled());
        $state = $this->rushState();
        self::assertSame(3, $state['gaveUp']);
        self::assertSame('2026-10-25T12:00:30+00:00', $state['finishedAt']);
        foreach ($this->buyers() as $buyer) {
            self::assertSame(sprintf('Rush buyer %d gave up: the rush ended 30 seconds after it started, the deadline of a rush set by RUSH_DEADLINE_SECONDS.', $buyer['buyerNumber']), $this->order($buyer['orderId'])['failureReason']);
        }
    }

    public function test_the_rush_state_reports_its_concert_and_the_buyers_it_was_asked_for_before_any_joined(): void
    {
        $this->concertOnSale(seats: 12);

        $this->startRush(buyers: 4, seatsPerBuyer: 2);
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertSame(
            ['rushId' => self::RUSH_ID, 'concertId' => self::CONCERT_ID, 'buyersRequested' => 4, 'seatsPerBuyer' => 2, 'created' => 0, 'held' => 0, 'paying' => 0, 'paid' => 0, 'issued' => 0, 'failed' => 0, 'gaveUp' => 0, 'startedAt' => '2026-10-25T12:00:00+00:00', 'finishedAt' => null],
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
        $this->expectExceptionMessage(sprintf('Concert %s is scheduled, not on sale, so a rush cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing and start a new rush, or start the rush on a concert listed as on_sale on /concerts.', self::CONCERT_ID));

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

    private function rushRuns(int $rounds = 20, int $secondsBetweenRounds = 2): void
    {
        for ($round = 0; $round < $rounds; $round++) {
            $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
            $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            $this->storefront->run('storefront', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            Carbon::setTestNow(Carbon::now()->addSeconds($secondsBetweenRounds));
        }
    }

    private function rushRunsWithoutTicketingAnsweringFor(int $seconds, int $secondsBetweenRounds = 1): void
    {
        for ($second = 0; $second < $seconds; $second += $secondsBetweenRounds) {
            $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            Carbon::setTestNow(Carbon::now()->addSeconds($secondsBetweenRounds));
        }
    }

    private function holdsReceived(): array
    {
        return array_values(array_filter($this->ticketingService->received, fn (array $request) => $request[0] === 'tickets.hold'));
    }

    private function rushMessagesHandled(): int
    {
        return $this->app->make($this->rushMessages)->handled;
    }

    private function stageCounts(): array
    {
        return array_intersect_key($this->rushState(), array_flip(['held', 'paying', 'paid', 'issued', 'failed', 'gaveUp']));
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
            'version' => 1,
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
