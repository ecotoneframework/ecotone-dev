<?php

declare(strict_types=1);

namespace Tests\Web;

use App\Customer\RegisterCustomer;
use App\Ordering\MarkSeatHeld;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @internal
 */
final class StorefrontPagesTest extends TestCase
{
    private const CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    private const CUSTOMER_COOKIE = 'storefront_customer';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
        DB::table('concerts')->delete();
        DB::table('seat_holds')->delete();
        DB::table('sold_seats')->delete();
        Carbon::setTestNow('2026-10-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_landing_page_shows_a_card_per_concert_with_its_date_venue_from_price_and_sale_state(): void
    {
        $this->schedule();
        $this->openSales();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeInOrder([
            '<a class="concert-card concert-card--on_sale" href="http://localhost/concerts/' . self::CONCERT_ID . '">',
            '<span class="date-badge__day">1</span><span class="date-badge__month">Dec</span>',
            '<span class="badge badge--on_sale">On sale</span>',
            '<span class="concert-card__artist">The Night Owls</span>',
            'Ecotone Hall',
            '1 Dec 2026, 20:00',
            'from <strong>EUR 45.00</strong>',
        ], escape: false);
    }

    public function test_the_seat_map_without_a_chosen_customer_greys_out_the_seats_ticketing_holds_or_sold(): void
    {
        $this->schedule();
        $this->openSales();
        $this->ticketingAnnounces('tickets.seat_sold', ['concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-1', 'orderId' => '0199f3a8-6f2a-7c1e-9a51-00000000000b']);
        $this->ticketingAnnounces('tickets.seat_held', ['concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/B-2', 'orderId' => '0199f3a8-6f2a-7c1e-9a51-00000000000c', 'holdId' => 'hold-1', 'expiresAt' => '2026-10-25T12:10:00+00:00']);

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertSee('<span class="seat seat--vip seat--sold" role="img" aria-label="A-1, VIP, sold" title="A-1, VIP, sold">1</span>', escape: false);
        $response->assertSee('<span class="seat seat--standard seat--held" role="img" aria-label="B-2, Standard, held by someone" title="B-2, Standard, held by someone">2</span>', escape: false);
        $response->assertSeeInOrder(['<strong data-seat-count="free">1</strong> free', '<strong data-seat-count="held">1</strong> held by someone', '<strong data-seat-count="sold">1</strong> sold'], escape: false);
    }

    public function test_the_empty_order_panel_shows_no_total_no_countdown_and_no_focusable_checkout_link(): void
    {
        $this->schedule();
        $this->openSales();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSee('<link rel="stylesheet" href="http://localhost/css/app.css">', escape: false);
        $response->assertSee('<p class="basket__expiry" data-basket-expiry hidden>', escape: false);
        $response->assertSee('<p class="basket__total" data-basket-total hidden>', escape: false);
        $response->assertSee('<a class="button button--block" data-basket-checkout hidden>', escape: false);
        $response->assertDontSee('href="#"', escape: false);
        self::assertMatchesRegularExpression('/^\[hidden\] \{\n    display: none !important;\n\}$/m', file_get_contents(public_path('css/app.css')));
    }

    public function test_whoami_lists_the_customers_to_pick_from(): void
    {
        $this->register('Ada Lovelace', 'ada@example.com');
        $this->register('Grace Hopper', 'grace@example.com');

        $response = $this->get('/whoami');

        $response->assertOk();
        $response->assertSeeInOrder(['Ada Lovelace', 'ada@example.com', 'Grace Hopper', 'grace@example.com']);
    }

    public function test_picking_a_customer_remembers_them_in_a_cookie(): void
    {
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $response = $this->post('/whoami', ['customerId' => $customerId]);

        $response->assertRedirect('/concerts');
        $response->assertCookie(self::CUSTOMER_COOKIE, $customerId);
    }

    public function test_picking_an_unknown_customer_is_not_found(): void
    {
        $this->post('/whoami', ['customerId' => '0199f3a8-6f2a-7c1e-9a51-000000000000'])->assertNotFound();
    }

    public function test_the_layout_names_the_chosen_customer(): void
    {
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/concerts');

        $response->assertOk();
        $response->assertSee('Shopping as <strong>Ada Lovelace</strong>', escape: false);
    }

    public function test_the_layout_asks_who_you_are_when_no_customer_is_chosen(): void
    {
        $response = $this->get('/concerts');

        $response->assertSeeInOrder(['<a href="http://localhost/whoami">', 'Pick who you are</a>'], escape: false);
    }

    public function test_the_concert_list_shows_a_scheduled_concert(): void
    {
        $this->schedule();

        $response = $this->get('/concerts');

        $response->assertOk();
        $response->assertSee('<a class="concert-card concert-card--scheduled" href="http://localhost/concerts/' . self::CONCERT_ID . '">', escape: false);
        $response->assertSeeInOrder(['<span class="badge badge--scheduled">Coming soon</span>', '<span class="concert-card__artist">The Night Owls</span>', 'Ecotone Hall', '1 Dec 2026, 20:00', 'from <strong>EUR 45.00</strong>'], escape: false);
    }

    public function test_the_concert_detail_shows_the_seat_grid_with_vip_seats_marked(): void
    {
        $this->schedule();

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSeeInOrder(['The Night Owls', 'VIP', 'EUR 120.00', 'Row A', 'A-1', 'Standard', 'EUR 45.00', 'Row B', 'B-1', 'B-2'], escape: false);
        $response->assertSee('<span class="seat seat--vip" role="img" aria-label="A-1, VIP, free" title="A-1, VIP, free">1</span>', escape: false);
        $response->assertSee('<span class="seat seat--standard" role="img" aria-label="B-1, Standard, free" title="B-1, Standard, free">1</span>', escape: false);
        $response->assertSeeInOrder(['<div class="stage" aria-hidden="true">Stage</div>', 'seat-block seat-block--vip', 'seat-block seat-block--standard', 'Back of the hall'], escape: false);
    }

    public function test_a_concert_whose_sales_opened_before_its_schedule_arrived_shows_on_sale_with_its_name_and_seats(): void
    {
        $this->openSales();
        $this->schedule();

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSeeInOrder(['The Night Owls', '<span class="status">On sale</span>', 'Row A', 'A-1', 'Row B', 'B-1', 'B-2'], escape: false);
    }

    public function test_a_concert_whose_sales_opened_after_its_schedule_shows_on_sale_with_its_name_and_seats(): void
    {
        $this->schedule();
        $this->openSales();

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSeeInOrder(['The Night Owls', '<span class="status">On sale</span>', 'Row A', 'A-1', 'Row B', 'B-1', 'B-2'], escape: false);
    }

    public function test_an_unknown_concert_is_not_found(): void
    {
        $this->get('/concerts/' . self::CONCERT_ID)->assertNotFound();
    }

    public function test_the_account_lists_the_chosen_customer_orders_with_their_status(): void
    {
        $this->schedule();
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');
        $otherCustomerId = $this->register('Grace Hopper', 'grace@example.com');
        $heldOrderId = $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));
        $this->commandBus()->send(new RequestSeat(orderId: $heldOrderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $heldOrderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: '2026-10-25T12:02:00+00:00'));
        $otherOrderId = $this->commandBus()->send(new StartOrder(customerId: $otherCustomerId, concertId: self::CONCERT_ID));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/account');

        $response->assertOk();
        $response->assertSeeInOrder(['The Night Owls', 'Seats held', 'A-1', $heldOrderId]);
        $response->assertDontSee($otherOrderId);
    }

    public function test_the_account_without_a_chosen_customer_sends_you_to_whoami(): void
    {
        $this->get('/account')->assertRedirect('/whoami');
    }

    public function test_the_account_of_a_customer_no_longer_registered_sends_you_to_whoami(): void
    {
        $customerId = $this->register('Ada Lovelace', 'ada@example.com');
        DB::table('customers')->delete();

        $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/account')->assertRedirect('/whoami');
    }

    public function test_the_layout_asks_who_you_are_when_the_chosen_customer_is_unknown(): void
    {
        $response = $this->withCookie(self::CUSTOMER_COOKIE, '0199f3a8-6f2a-7c1e-9a51-000000000000')->get('/concerts');

        $response->assertOk();
        $response->assertSeeInOrder(['<a href="http://localhost/whoami">', 'Pick who you are</a>'], escape: false);
    }

    public function test_the_layout_asks_who_you_are_when_the_customer_cookie_is_not_a_customer_id(): void
    {
        $response = $this->withCookie(self::CUSTOMER_COOKIE, 'not-a-customer-id')->get('/concerts');

        $response->assertOk();
        $response->assertSeeInOrder(['<a href="http://localhost/whoami">', 'Pick who you are</a>'], escape: false);
    }

    private function ticketingAnnounces(string $routingKey, array $event): void
    {
        $this->app->make(EventBus::class)->publishWithRouting($routingKey, $event, metadata: ['concertId' => $event['concertId']]);
    }

    private function register(string $name, string $email): string
    {
        return $this->commandBus()->send(new RegisterCustomer(name: $name, email: $email));
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

    private function openSales(): void
    {
        $this->app->make(EventBus::class)->publishWithRouting('concert.sales_opened', ['concertId' => self::CONCERT_ID], metadata: ['concertId' => self::CONCERT_ID]);
    }

    private function commandBus(): CommandBus
    {
        return $this->app->make(CommandBus::class);
    }
}
