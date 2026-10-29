<?php

declare(strict_types=1);

namespace Tests\Concert;

use App\Customer\RegisterCustomer;
use App\Ordering\BeginPayment;
use App\Ordering\MarkPaid;
use App\Ordering\MarkSeatHeld;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @internal
 */
final class SeatStatesTest extends TestCase
{
    private const CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    private const OTHER_CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e41';
    private const ORDER_ID = '0199f3a8-6f2a-7c1e-9a51-0000000000a1';
    private const OTHER_ORDER_ID = '0199f3a8-6f2a-7c1e-9a51-0000000000a2';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
        DB::table('seat_holds')->delete();
        DB::table('sold_seats')->delete();
        Carbon::setTestNow('2026-10-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_seat_ticketing_holds_for_another_order_is_held(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');

        self::assertSame(['heldSeatIds' => [$this->seat('A-1')], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_seat_ticketing_sold_is_sold_and_not_held(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->sold('A-1', self::OTHER_ORDER_ID);

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => [$this->seat('A-1')]], $this->seatStates());
    }

    public function test_a_released_hold_frees_the_seat(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->released('A-1', self::OTHER_ORDER_ID, 'hold-1');

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_release_delivered_before_its_hold_keeps_the_seat_free(): void
    {
        $this->released('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_release_delivered_after_the_sale_never_frees_the_sold_seat(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->sold('A-1', self::OTHER_ORDER_ID);
        $this->released('A-1', self::OTHER_ORDER_ID, 'hold-1');

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => [$this->seat('A-1')]], $this->seatStates());
    }

    public function test_a_hold_delivered_after_the_sale_leaves_the_seat_sold(): void
    {
        $this->sold('A-1', self::OTHER_ORDER_ID);
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => [$this->seat('A-1')]], $this->seatStates());
    }

    public function test_a_later_hold_stays_held_whichever_order_the_earlier_hold_and_its_release_arrive_in(): void
    {
        $this->held('A-1', self::ORDER_ID, 'hold-2');
        $this->released('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');

        self::assertSame(['heldSeatIds' => [$this->seat('A-1')], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_redelivered_events_end_in_the_same_state(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->released('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->released('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1');
        $this->held('B-1', self::OTHER_ORDER_ID, 'hold-2');
        $this->held('B-1', self::OTHER_ORDER_ID, 'hold-2');
        $this->sold('C-1', self::OTHER_ORDER_ID);
        $this->sold('C-1', self::OTHER_ORDER_ID);

        self::assertSame(['heldSeatIds' => [$this->seat('B-1')], 'soldSeatIds' => [$this->seat('C-1')]], $this->seatStates());
    }

    public function test_the_holds_of_the_excluded_order_are_left_out(): void
    {
        $this->held('A-1', self::ORDER_ID, 'hold-1');
        $this->held('B-1', self::OTHER_ORDER_ID, 'hold-2');

        self::assertSame(['heldSeatIds' => [$this->seat('B-1')], 'soldSeatIds' => []], $this->seatStates(excludingOrderId: self::ORDER_ID));
    }

    public function test_the_seats_of_another_concert_are_left_out(): void
    {
        $this->publish('tickets.seat_held', ['concertId' => self::OTHER_CONCERT_ID, 'seatId' => self::OTHER_CONCERT_ID . '/A-1', 'orderId' => self::OTHER_ORDER_ID, 'holdId' => 'hold-1', 'expiresAt' => '2026-10-25T12:10:00+00:00']);
        $this->publish('tickets.seat_sold', ['concertId' => self::OTHER_CONCERT_ID, 'seatId' => self::OTHER_CONCERT_ID . '/B-1', 'orderId' => self::OTHER_ORDER_ID]);

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_seat_another_storefront_order_asked_ticketing_for_is_held_before_ticketing_announces_it(): void
    {
        $orderId = $this->commandBus()->send(new StartOrder(customerId: $this->commandBus()->send(new RegisterCustomer(name: 'Grace Hopper', email: 'grace@example.com')), concertId: self::CONCERT_ID));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: $this->seat('B-2'), holdId: 'hold-3'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: $this->seat('B-2'), holdId: 'hold-3', expiresAt: '2026-10-25T12:02:00+00:00'));
        $this->sold('C-1', self::OTHER_ORDER_ID);

        self::assertSame(['heldSeatIds' => [$this->seat('B-2')], 'soldSeatIds' => [$this->seat('C-1')]], $this->seatStates());
        self::assertSame([$this->seat('B-2'), $this->seat('C-1')], $this->app->make(QueryBus::class)->sendWithRouting('concert.taken_seats', ['concertId' => self::CONCERT_ID, 'excludingOrderId' => null]));
    }

    public function test_a_hold_frees_its_seat_once_its_expiry_passes(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1', expiresAt: '2026-10-25T12:02:00+00:00');
        Carbon::setTestNow('2026-10-25 12:01:59');

        self::assertSame(['heldSeatIds' => [$this->seat('A-1')], 'soldSeatIds' => []], $this->seatStates());

        Carbon::setTestNow('2026-10-25 12:02:00');

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_hold_past_its_expiry_no_longer_holds_the_seat_although_ticketing_never_announced_its_release(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1', expiresAt: '2026-10-25T12:02:00+00:00');
        Carbon::setTestNow('2026-10-25 12:03:00');
        $this->held('A-1', self::ORDER_ID, 'hold-2', expiresAt: '2026-10-25T12:13:00+00:00');
        $this->released('A-1', self::ORDER_ID, 'hold-2');

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_release_of_an_expired_hold_arriving_after_another_order_took_the_seat_over_keeps_it_held(): void
    {
        $this->held('A-1', self::OTHER_ORDER_ID, 'hold-1', expiresAt: '2026-10-25T12:02:00+00:00');
        Carbon::setTestNow('2026-10-25 12:03:00');
        $this->held('A-1', self::ORDER_ID, 'hold-2', expiresAt: '2026-10-25T12:13:00+00:00');
        $this->released('A-1', self::OTHER_ORDER_ID, 'hold-1');

        self::assertSame(['heldSeatIds' => [$this->seat('A-1')], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_seat_another_storefront_order_holds_frees_once_its_expiry_passes_although_ticketing_never_announced_the_expiry(): void
    {
        $orderId = $this->commandBus()->send(new StartOrder(customerId: $this->commandBus()->send(new RegisterCustomer(name: 'Grace Hopper', email: 'grace@example.com')), concertId: self::CONCERT_ID));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: $this->seat('B-2'), holdId: 'hold-3'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: $this->seat('B-2'), holdId: 'hold-3', expiresAt: '2026-10-25T12:02:00+00:00'));
        Carbon::setTestNow('2026-10-25 12:02:00');

        self::assertSame(['heldSeatIds' => [], 'soldSeatIds' => []], $this->seatStates());
    }

    public function test_a_seat_of_a_paid_storefront_order_stays_held_past_its_expiry_until_ticketing_announces_the_sale(): void
    {
        $orderId = $this->commandBus()->send(new StartOrder(customerId: $this->commandBus()->send(new RegisterCustomer(name: 'Grace Hopper', email: 'grace@example.com')), concertId: self::CONCERT_ID));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: $this->seat('B-2'), holdId: 'hold-3'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: $this->seat('B-2'), holdId: 'hold-3', expiresAt: '2026-10-25T12:02:00+00:00'));
        $this->commandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));
        $this->commandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));
        Carbon::setTestNow('2026-10-25 12:02:00');

        self::assertSame(['heldSeatIds' => [$this->seat('B-2')], 'soldSeatIds' => []], $this->seatStates());
    }

    private function held(string $seatCode, string $orderId, string $holdId, string $expiresAt = '2026-10-25T12:10:00+00:00'): void
    {
        $this->publish('tickets.seat_held', ['concertId' => self::CONCERT_ID, 'seatId' => $this->seat($seatCode), 'orderId' => $orderId, 'holdId' => $holdId, 'expiresAt' => $expiresAt]);
    }

    private function released(string $seatCode, string $orderId, string $holdId): void
    {
        $this->publish('tickets.seat_released', ['concertId' => self::CONCERT_ID, 'seatId' => $this->seat($seatCode), 'orderId' => $orderId, 'holdId' => $holdId]);
    }

    private function sold(string $seatCode, string $orderId): void
    {
        $this->publish('tickets.seat_sold', ['concertId' => self::CONCERT_ID, 'seatId' => $this->seat($seatCode), 'orderId' => $orderId]);
    }

    private function publish(string $routingKey, array $event): void
    {
        $this->app->make(EventBus::class)->publishWithRouting($routingKey, $event, metadata: ['concertId' => $event['concertId']]);
    }

    private function seatStates(?string $excludingOrderId = null): array
    {
        return $this->app->make(QueryBus::class)->sendWithRouting('concert.seat_states', ['concertId' => self::CONCERT_ID, 'excludingOrderId' => $excludingOrderId]);
    }

    private function seat(string $seatCode): string
    {
        return self::CONCERT_ID . '/' . $seatCode;
    }

    private function commandBus(): CommandBus
    {
        return $this->app->make(CommandBus::class);
    }
}
