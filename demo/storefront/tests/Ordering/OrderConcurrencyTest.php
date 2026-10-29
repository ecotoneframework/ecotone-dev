<?php

declare(strict_types=1);

namespace Tests\Ordering;

use App\Concert\ConcertCatalogue;
use App\Concert\ConcertProjector;
use App\Ordering\FailOrder;
use App\Ordering\MarkSeatHeld;
use App\Ordering\Order;
use App\Ordering\RequestSeat;
use App\Ordering\TicketingReplies;
use App\Payment\Checkout;
use App\Payment\PaymentAttempts;
use App\Payment\PaymentProvider;
use App\Rush\Rushes;
use App\Rush\RushSimulator;
use App\Rush\StartRush;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @internal
 */
final class OrderConcurrencyTest extends StorefrontFlowTestCase
{
    private const RUSH_ID = '0199f3a8-6f2a-7c1e-9a51-7a3c5e9b2d10';

    private string $anotherConsumer;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('rush_buyers')->delete();
        DB::table('rushes')->delete();
        DB::table('payment_attempts')->delete();
    }

    protected function storefrontServices(): array
    {
        config(['database.connections.another_consumer' => config('database.connections.pgsql')]);
        $anotherConsumer = new class () {
            public ?object $commitsWhileSeatIsMarkedHeld = null;

            public ?object $commitsWhileOrderFails = null;

            #[Around(pointcut: Order::class . '::markSeatHeld')]
            public function commitWhileSeatIsMarkedHeld(MethodInvocation $invocation, #[Reference] CommandBus $commandBus): mixed
            {
                $command = $this->commitsWhileSeatIsMarkedHeld;
                $this->commitsWhileSeatIsMarkedHeld = null;

                return $this->commitFirst(command: $command, invocation: $invocation, commandBus: $commandBus);
            }

            #[Around(pointcut: Order::class . '::fail')]
            public function commitWhileOrderFails(MethodInvocation $invocation, #[Reference] CommandBus $commandBus): mixed
            {
                $command = $this->commitsWhileOrderFails;
                $this->commitsWhileOrderFails = null;

                return $this->commitFirst(command: $command, invocation: $invocation, commandBus: $commandBus);
            }

            private function commitFirst(?object $command, MethodInvocation $invocation, CommandBus $commandBus): mixed
            {
                if ($command !== null) {
                    $connection = DB::getDefaultConnection();
                    DB::setDefaultConnection('another_consumer');
                    try {
                        $commandBus->send($command);
                    } finally {
                        DB::setDefaultConnection($connection);
                    }
                }

                return $invocation->proceed();
            }
        };
        $this->anotherConsumer = $anotherConsumer::class;

        return [$this->anotherConsumer, TicketingReplies::class, RushSimulator::class, Rushes::class, ConcertProjector::class, ConcertCatalogue::class, Checkout::class, PaymentProvider::class, PaymentAttempts::class];
    }

    public function test_a_held_seat_marked_on_the_command_bus_while_another_consumer_fails_the_same_order_is_retried_and_releases_the_seat(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->app->make($this->anotherConsumer)->commitsWhileSeatIsMarkedHeld = new FailOrder(orderId: $orderId, reason: 'the rush deadline passed');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
        $this->ticketingReceives();

        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $order = $this->order($orderId);
        self::assertSame('failed', $order['status']);
        self::assertSame([], $order['heldSeats']);
        self::assertContains(['tickets.release', self::CONCERT_ID . '/A-12'], array_map(fn (array $request) => [$request[0], $request[1]['seatId']], $this->ticketingReceives()));
    }

    public function test_a_held_reply_that_loses_to_a_failure_of_its_order_is_retried_and_releases_the_seat(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->app->make($this->anotherConsumer)->commitsWhileSeatIsMarkedHeld = new FailOrder(orderId: $orderId, reason: 'the rush deadline passed');
        $this->ticketingService->holdReplies[] = ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'holdId' => 'hold-1', 'outcome' => 'held', 'reason' => null, 'expiresAt' => self::EXPIRES_AT];
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
        $this->ticketingReceives();

        $this->storefront->run('storefront', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame('failed', $this->order($orderId)['status']);
        self::assertSame(0, $this->storefront->getGateway(DeadLetterGateway::class)->count());
        self::assertContains(['tickets.release', self::CONCERT_ID . '/A-12'], array_map(fn (array $request) => [$request[0], $request[1]['seatId']], $this->ticketingReceives()));
    }

    public function test_a_rush_tick_whose_failure_of_an_order_loses_to_a_held_reply_is_retried_and_the_rush_still_advances(): void
    {
        $this->concertOnSale(seats: 12);
        $this->ticketingService->holdsToRefuse = 3;
        $this->storefront->getCommandBus()->send(new StartRush(rushId: self::RUSH_ID, concertId: self::CONCERT_ID, buyers: 1, declinedShare: 0, outageShare: 0, bounceShare: 0, seatsPerBuyer: 1));
        $this->rushRuns(rounds: 4);
        $orderId = $this->storefront->sendQueryWithRouting('rush.buyers', self::RUSH_ID)[0]['orderId'];
        $this->app->make($this->anotherConsumer)->commitsWhileOrderFails = new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1', expiresAt: self::EXPIRES_AT);

        $this->rushRuns(rounds: 20);

        self::assertNull($this->app->make($this->anotherConsumer)->commitsWhileOrderFails);
        self::assertSame(0, $this->storefront->getGateway(DeadLetterGateway::class)->count());
        $state = $this->storefront->sendQueryWithRouting('rush.state', self::RUSH_ID);
        self::assertSame(1, $state['gaveUp']);
        self::assertNotNull($state['finishedAt']);
        self::assertSame('failed', $this->order($orderId)['status']);
    }

    public function test_an_order_changed_one_command_after_another_saves_every_change(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-11');
        $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'the rush deadline passed'));

        $order = $this->order($orderId);
        self::assertSame('failed', $order['status']);
        self::assertCount(2, $order['heldSeats']);
    }

    private function rushRuns(int $rounds): void
    {
        for ($round = 0; $round < $rounds; $round++) {
            $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
            $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            $this->storefront->run('storefront', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
            Carbon::setTestNow(Carbon::now()->addSeconds(2));
        }
    }

    private function concertOnSale(int $seats): void
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
        $distributedBus->convertAndPublishEvent('concert.sales_opened', ['concertId' => self::CONCERT_ID], metadata: ['concertId' => self::CONCERT_ID]);
        $this->storefrontConsumes();
    }
}
