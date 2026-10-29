<?php

declare(strict_types=1);

namespace Tests\Web;

use App\Customer\RegisterCustomer;
use App\Ordering\BeginPayment;
use App\Ordering\FailOrder;
use App\Ordering\MarkPaid;
use App\Ordering\MarkSeatHeld;
use App\Ordering\MarkTicketsIssued;
use App\Ordering\RecordSeatRefusal;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * @internal
 */
final class CheckoutPagesTest extends TestCase
{
    private const CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    private const CUSTOMER_COOKIE = 'storefront_customer';
    private const EXPIRES_AT = '2026-10-25T12:02:00+00:00';
    private const LATER_EXPIRES_AT = '2026-10-25T12:04:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
        DB::table('concerts')->delete();
        DB::table('payment_attempts')->delete();
        DB::table('seat_holds')->delete();
        DB::table('sold_seats')->delete();
    }

    public function test_the_seat_picker_greys_out_the_seats_ticketing_holds_or_sold_with_their_state_and_counts_them(): void
    {
        $this->scheduleOnSale();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');
        $this->ticketingAnnounces('tickets.seat_sold', ['concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-1', 'orderId' => '0199f3a8-6f2a-7c1e-9a51-00000000000b']);
        $this->ticketingAnnounces('tickets.seat_held', ['concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/B-2', 'orderId' => '0199f3a8-6f2a-7c1e-9a51-00000000000c', 'holdId' => 'hold-1']);

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/concerts/' . self::CONCERT_ID);

        $response->assertSee('<button type="button" class="seat seat--vip seat--sold" data-seat-id="' . self::CONCERT_ID . '/A-1" data-seat-code="A-1" data-tier="vip" aria-label="A-1, VIP, sold" title="A-1, VIP, sold" disabled>1</button>', escape: false);
        $response->assertSee('<button type="button" class="seat seat--standard seat--held" data-seat-id="' . self::CONCERT_ID . '/B-2" data-seat-code="B-2" data-tier="standard" aria-label="B-2, Standard, held by someone" title="B-2, Standard, held by someone" disabled>2</button>', escape: false);
        $response->assertSee('<button type="button" class="seat seat--standard" data-seat-id="' . self::CONCERT_ID . '/B-1" data-seat-code="B-1" data-tier="standard" aria-label="B-1, Standard, free" title="B-1, Standard, free">1</button>', escape: false);
        $response->assertSeeInOrder(['<strong data-seat-count="free">1</strong> free', '<strong data-seat-count="yours">0</strong> yours', '<strong data-seat-count="held">1</strong> held by someone', '<strong data-seat-count="sold">1</strong> sold'], escape: false);
        $response->assertSee('data-sold-seat-ids="' . e(json_encode([self::CONCERT_ID . '/A-1'])) . '"', escape: false);
    }

    public function test_the_seat_picker_shows_the_seats_the_customer_holds_as_theirs_although_ticketing_announced_the_hold(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $this->ticketingAnnounces('tickets.seat_held', ['concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/B-1', 'orderId' => $orderId, 'holdId' => 'hold-1']);

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/concerts/' . self::CONCERT_ID);

        $response->assertSee('<button type="button" class="seat seat--standard seat--yours" data-seat-id="' . self::CONCERT_ID . '/B-1" data-seat-code="B-1" data-tier="standard" aria-label="B-1, Standard, yours" title="B-1, Standard, yours">1</button>', escape: false);
        $response->assertSee('<strong data-seat-count="yours">1</strong> yours', escape: false);
        $response->assertSee('<strong data-seat-count="held">0</strong> held by someone', escape: false);
    }

    public function test_the_order_state_lists_the_seats_ticketing_sold_apart_from_the_held_ones(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->ticketingAnnounces('tickets.seat_sold', ['concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-1', 'orderId' => '0199f3a8-6f2a-7c1e-9a51-00000000000b']);
        $this->ticketingAnnounces('tickets.seat_held', ['concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/B-2', 'orderId' => '0199f3a8-6f2a-7c1e-9a51-00000000000c', 'holdId' => 'hold-1']);

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->getJson('/orders/' . $orderId . '/state.json');

        $response->assertJsonPath('takenSeatIds', [self::CONCERT_ID . '/A-1', self::CONCERT_ID . '/B-2']);
        $response->assertJsonPath('soldSeatIds', [self::CONCERT_ID . '/A-1']);
    }

    public function test_the_checkout_page_keeps_the_demo_choices_in_a_demo_controls_box_apart_from_the_pay_button(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId . '/checkout');

        $response->assertSeeInOrder([
            'Paying as <strong>Ada Lovelace</strong>',
            '<div class="demo-controls" role="group" aria-labelledby="demo-controls-title">',
            'Demo controls',
            'This shop has no real payment provider or mail server: choose how the stubs answer.',
            'name="paymentOutcome"',
            'name="confirmationEmailBounces"',
            '</div>',
            '<button type="submit" class="button button--block">Pay EUR 120.00</button>',
        ], escape: false);
    }

    public function test_a_chosen_customer_picks_seats_of_a_concert_on_sale_with_the_seat_picker(): void
    {
        $this->scheduleOnSale();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSee('<button type="button" class="seat seat--vip" data-seat-id="' . self::CONCERT_ID . '/A-1" data-seat-code="A-1" data-tier="vip" aria-label="A-1, VIP, free" title="A-1, VIP, free">1</button>', escape: false);
        $response->assertSee('data-start-order-url="http://localhost/concerts/' . self::CONCERT_ID . '/orders"', escape: false);
        $response->assertSee('data-order-id=""', escape: false);
        $response->assertSee('<script src="http://localhost/js/seat-picker.js"></script>', escape: false);
    }

    public function test_the_seat_picker_continues_the_open_order_of_the_customer(): void
    {
        $this->scheduleOnSale();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');
        $orderId = $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/concerts/' . self::CONCERT_ID);

        $response->assertSee('data-order-id="' . $orderId . '"', escape: false);
    }

    public function test_a_concert_page_without_a_chosen_customer_shows_the_seats_without_the_picker(): void
    {
        $this->scheduleOnSale();

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertDontSee('data-seat-id', escape: false);
        $response->assertSee('<a href="http://localhost/whoami">Pick who you are</a> to choose seats.', escape: false);
    }

    public function test_a_sold_out_concert_page_says_sold_out_and_offers_no_seat_picker(): void
    {
        $this->scheduleOnSale();
        $this->app->make(EventBus::class)->publishWithRouting('concert.sold_out', ['concertId' => self::CONCERT_ID], metadata: ['concertId' => self::CONCERT_ID]);
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSee('<p class="notice notice--sold-out">Sold out</p>', escape: false);
        $response->assertDontSee('data-seat-id', escape: false);
    }

    public function test_starting_an_order_from_the_concert_page_returns_the_open_order_of_the_customer(): void
    {
        $this->scheduleOnSale();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $first = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->postJson('/concerts/' . self::CONCERT_ID . '/orders');
        $second = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->postJson('/concerts/' . self::CONCERT_ID . '/orders');

        $first->assertOk();
        self::assertSame($first->json('orderId'), $second->json('orderId'));
        self::assertSame([['orderId' => $first->json('orderId'), 'concertId' => self::CONCERT_ID, 'status' => 'started', 'seatIds' => []]], $this->app->make(QueryBus::class)->sendWithRouting('customer.orders', $customerId));
    }

    public function test_starting_an_order_without_a_chosen_customer_is_refused(): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/orders');

        $response->assertForbidden();
        $response->assertJson(['error' => 'No customer is chosen, so there is no one to start the order for. Pick who you are on /whoami, then pick the seats again.']);
    }

    public function test_starting_an_order_for_a_concert_not_on_sale_is_refused(): void
    {
        $this->schedule();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->postJson('/concerts/' . self::CONCERT_ID . '/orders');

        $response->assertConflict();
        $response->assertJson(['error' => sprintf('Concert %s is scheduled, not on sale, so its seats cannot be picked yet. Wait until ticketing opens its sales, then pick the seats again.', self::CONCERT_ID)]);
    }

    public function test_picking_a_seat_requests_its_hold_from_ticketing(): void
    {
        [$customerId, $orderId] = $this->startedOrder();

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->postJson('/orders/' . $orderId . '/seats', ['seatId' => self::CONCERT_ID . '/A-1']);

        $response->assertAccepted();
        $response->assertJson(['requested' => self::CONCERT_ID . '/A-1']);
    }

    public function test_picking_a_seat_the_order_holds_is_a_conflict_naming_the_refusal(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->postJson('/orders/' . $orderId . '/seats', ['seatId' => self::CONCERT_ID . '/A-1']);

        $response->assertConflict();
        $response->assertJson(['error' => sprintf('Order %s already holds seat %s/A-1, so RequestSeat does not ask ticketing for it again. Pick another seat, or release this one with ReleaseSeat.', $orderId, self::CONCERT_ID)]);
    }

    public function test_releasing_a_held_seat_removes_it_from_the_order_state(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->postJson('/orders/' . $orderId . '/seats/release', ['seatId' => self::CONCERT_ID . '/A-1']);

        $response->assertOk();
        self::assertSame([], $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->getJson('/orders/' . $orderId . '/state.json')->json('heldSeats'));
    }

    public function test_the_order_state_reports_held_seats_with_their_expiry_and_the_earliest_expiry(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1', expiresAt: self::LATER_EXPIRES_AT));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->getJson('/orders/' . $orderId . '/state.json');

        $response->assertOk();
        $response->assertExactJson([
            'orderId' => $orderId,
            'concertId' => self::CONCERT_ID,
            'status' => 'seats_held',
            'heldSeats' => [
                ['seatId' => self::CONCERT_ID . '/B-1', 'seatCode' => 'B-1', 'expiresAt' => self::LATER_EXPIRES_AT],
                ['seatId' => self::CONCERT_ID . '/A-1', 'seatCode' => 'A-1', 'expiresAt' => self::EXPIRES_AT],
            ],
            'earliestExpiresAt' => self::EXPIRES_AT,
            'lastRefusal' => null,
            'takenSeatIds' => [],
            'soldSeatIds' => [],
            'failureReason' => null,
            'tickets' => [],
        ]);
    }

    public function test_the_order_state_reports_the_last_refusal(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused'));
        $this->commandBus()->send(new RecordSeatRefusal(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused', reason: 'someone was faster: seat A-1'));

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->getJson('/orders/' . $orderId . '/state.json');

        $response->assertJsonPath('lastRefusal', ['seatId' => self::CONCERT_ID . '/A-1', 'reason' => 'someone was faster: seat A-1']);
    }

    public function test_the_order_state_marks_seats_of_other_live_orders_of_the_concert_as_taken(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $graceOrderId = $this->commandBus()->send(new StartOrder(customerId: $this->register('Grace Hopper', 'grace@example.com'), concertId: self::CONCERT_ID));
        $this->commandBus()->send(new RequestSeat(orderId: $graceOrderId, seatId: self::CONCERT_ID . '/B-2', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $graceOrderId, seatId: self::CONCERT_ID . '/B-2', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $linusOrderId = $this->commandBus()->send(new StartOrder(customerId: $this->register('Linus Pauling', 'linus@example.com'), concertId: self::CONCERT_ID));
        $this->commandBus()->send(new RequestSeat(orderId: $linusOrderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $linusOrderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $this->commandBus()->send(new FailOrder(orderId: $linusOrderId, reason: 'The card was declined'));

        $response = $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->getJson('/orders/' . $orderId . '/state.json');

        $response->assertJsonPath('takenSeatIds', [self::CONCERT_ID . '/B-2']);
    }

    public function test_the_order_of_another_customer_is_not_found(): void
    {
        [, $orderId] = $this->startedOrder();
        $graceId = $this->register('Grace Hopper', 'grace@example.com');

        $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $graceId)->getJson('/orders/' . $orderId . '/state.json')->assertNotFound();
        $this->withCookie(self::CUSTOMER_COOKIE, $graceId)->get('/orders/' . $orderId)->assertNotFound();
        $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $graceId)->postJson('/orders/' . $orderId . '/seats', ['seatId' => self::CONCERT_ID . '/A-1'])->assertNotFound();
    }

    public function test_an_order_that_does_not_exist_is_not_found(): void
    {
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/0199f3a8-6f2a-7c1e-9a51-000000000000')->assertNotFound();
        $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/not-an-order-id')->assertNotFound();
    }

    public function test_the_checkout_page_lists_the_held_seats_with_their_prices_and_the_total(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId . '/checkout');

        $response->assertOk();
        $response->assertSeeInOrder(['A-1', 'VIP', 'EUR 120.00', 'B-1', 'Standard', 'EUR 45.00', 'Total', 'EUR 165.00']);
        $response->assertSee('<form method="post" action="http://localhost/orders/' . $orderId . '/checkout"', escape: false);
        $response->assertDontSee('cardNumber', escape: false);
    }

    public function test_the_checkout_page_offers_the_three_payment_outcomes_in_plain_words_with_success_chosen(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId . '/checkout');

        $response->assertSeeInOrder([
            '<input type="radio" name="paymentOutcome" value="succeeds" checked> The payment succeeds',
            '<input type="radio" name="paymentOutcome" value="declined"> The card is declined: the order fails and its seats are released',
            '<input type="radio" name="paymentOutcome" value="provider_outage"> The payment provider is down: the charge lands in the dead letters until you replay it from Ecotone Pulse',
        ], escape: false);
    }

    public function test_the_checkout_page_names_both_ways_to_replay_a_charge_hit_by_the_provider_outage(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId . '/checkout');

        $response->assertSee('the charge lands in the dead letters until you replay it from Ecotone Pulse or with <code>php artisan ecotone:deadletter:replay &lt;messageId&gt;</code> in the storefront', escape: false);
    }

    #[DataProvider('paymentOutcomes')]
    public function test_paying_on_the_checkout_page_with_any_outcome_moves_the_order_to_paying_and_shows_the_order(string $paymentOutcome): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->post('/orders/' . $orderId . '/checkout', ['paymentOutcome' => $paymentOutcome]);

        $response->assertRedirect('/orders/' . $orderId);
        self::assertSame('paying', $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->getJson('/orders/' . $orderId . '/state.json')->json('status'));
    }

    public function test_the_checkout_page_offers_to_let_the_confirmation_email_bounce_unticked(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId . '/checkout');

        $response->assertSee('<input type="checkbox" name="confirmationEmailBounces" value="1"> The confirmation email bounces: it lands in the dead letters, and the entry codes stay on the order page', escape: false);
    }

    public function test_paying_with_the_confirmation_email_chosen_to_bounce_keeps_the_choice_with_the_order(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->post('/orders/' . $orderId . '/checkout', ['paymentOutcome' => 'succeeds', 'confirmationEmailBounces' => '1']);

        self::assertTrue($this->app->make(QueryBus::class)->sendWithRouting('order.detail', $orderId)['confirmationEmailBounces']);
        $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId)->assertSee('You chose at checkout that the confirmation email bounces, so the entry codes reach you here, on the order page.');
    }

    public function test_paying_without_the_bounce_ticked_sends_the_confirmation_email(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->post('/orders/' . $orderId . '/checkout', ['paymentOutcome' => 'succeeds']);

        self::assertFalse($this->app->make(QueryBus::class)->sendWithRouting('order.detail', $orderId)['confirmationEmailBounces']);
        $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId)->assertDontSee('the confirmation email bounces');
    }

    public static function paymentOutcomes(): iterable
    {
        yield 'the payment succeeds' => ['succeeds'];
        yield 'the card is declined' => ['declined'];
        yield 'the payment provider is down' => ['provider_outage'];
    }

    public function test_paying_with_an_outcome_the_demo_provider_does_not_know_shows_the_refusal_on_the_checkout_page(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->from('/orders/' . $orderId . '/checkout')->post('/orders/' . $orderId . '/checkout', ['paymentOutcome' => 'maybe']);

        $response->assertRedirect('/orders/' . $orderId . '/checkout');
        $response->assertSessionHas('refusal', sprintf('The payment outcome "maybe" is not one the demo payment provider plays, so order %s is not paid. Choose succeeds (the payment succeeds), declined (the card is declined) or provider_outage (the payment provider is down).', $orderId));
        self::assertSame('seats_held', $this->withCredentials()->withCookie(self::CUSTOMER_COOKIE, $customerId)->getJson('/orders/' . $orderId . '/state.json')->json('status'));
    }

    public function test_the_order_page_shows_the_held_seats_counting_down_to_their_expiry(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId);

        $response->assertOk();
        $response->assertSeeInOrder(['The Night Owls', 'Seats held', 'A-1']);
        $response->assertSee('<span class="countdown" data-expires-at="' . self::EXPIRES_AT . '"></span>', escape: false);
        $response->assertSee('<a class="button" href="http://localhost/orders/' . $orderId . '/checkout">Pay for these seats</a>', escape: false);
    }

    public function test_the_order_page_shows_the_payment_state_while_paid(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $this->commandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));
        $this->commandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId);

        $response->assertSeeInOrder(['paid', 'Payment', 'pay_123', 'Waiting for ticketing to issue the tickets.']);
    }

    public function test_the_order_page_shows_the_issued_tickets_with_their_entry_codes(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $this->commandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));
        $this->commandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));
        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: [['ticketNumber' => 'T-0001', 'seatId' => self::CONCERT_ID . '/A-1', 'tier' => 'vip', 'entryCode' => 'ENTRY-7F3A']]));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId);

        $response->assertSeeInOrder(['Tickets issued', 'Tickets', '<li class="ticket-stub ticket-stub--vip">', 'The Night Owls', 'A-1', 'VIP', 'T-0001', '<code class="entry-code">ENTRY-7F3A</code>'], escape: false);
    }

    public function test_the_order_page_shows_why_the_order_failed(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new FailOrder(orderId: $orderId, reason: 'The card was declined'));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId);

        $response->assertSeeInOrder(['failed', 'The card was declined']);
    }

    public function test_the_order_page_shows_the_last_refusal(): void
    {
        [$customerId, $orderId] = $this->startedOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused'));
        $this->commandBus()->send(new RecordSeatRefusal(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused', reason: 'someone was faster: seat A-1'));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId);

        $response->assertSeeInOrder(['A-1', 'someone was faster: seat A-1']);
    }

    public function test_the_account_links_each_order_to_its_page(): void
    {
        [$customerId, $orderId] = $this->startedOrder();

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/account');

        $response->assertSee('<a href="http://localhost/orders/' . $orderId . '"><code>' . $orderId . '</code></a>', escape: false);
    }

    private function ticketingAnnounces(string $routingKey, array $event): void
    {
        $this->app->make(EventBus::class)->publishWithRouting($routingKey, $event, metadata: ['concertId' => $event['concertId']]);
    }

    private function startedOrder(): array
    {
        $this->scheduleOnSale();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        return [$customerId, $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID))];
    }

    private function register(string $name, string $email): string
    {
        return $this->commandBus()->send(new RegisterCustomer(name: $name, email: $email));
    }

    private function scheduleOnSale(): void
    {
        $this->schedule();
        $this->app->make(EventBus::class)->publishWithRouting('concert.sales_opened', ['concertId' => self::CONCERT_ID], metadata: ['concertId' => self::CONCERT_ID]);
    }

    private function schedule(): void
    {
        $this->app->make(EventBus::class)->publishWithRouting('concert.scheduled', [
            'concertId' => self::CONCERT_ID,
            'version' => 1,
            'venueName' => 'Ecotone Hall',
            'artist' => 'The Night Owls',
            'startsAt' => '2026-12-01T20:00:00+00:00',
            'currency' => 'EUR',
            'tiers' => ['standard' => 4500, 'vip' => 12000],
            'seats' => [
                ['seatId' => self::CONCERT_ID . '/B-2', 'seatCode' => 'B-2', 'tier' => 'standard'],
                ['seatId' => self::CONCERT_ID . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip'],
                ['seatId' => self::CONCERT_ID . '/B-1', 'seatCode' => 'B-1', 'tier' => 'standard'],
            ],
        ], metadata: ['concertId' => self::CONCERT_ID]);
    }

    private function commandBus(): CommandBus
    {
        return $this->app->make(CommandBus::class);
    }
}
