<?php

declare(strict_types=1);

namespace Tests\Web;

use App\Customer\RegisterCustomer;
use App\Ordering\MarkSeatsHeld;
use App\Ordering\StartOrder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
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

        $response->assertSee('<a href="http://localhost/whoami">Pick who you are</a>', escape: false);
    }

    public function test_the_concert_list_shows_a_scheduled_concert(): void
    {
        $this->schedule();

        $response = $this->get('/concerts');

        $response->assertOk();
        $response->assertSee('<a href="http://localhost/concerts/' . self::CONCERT_ID . '">The Night Owls</a>', escape: false);
        $response->assertSeeInOrder(['Ecotone Hall', '1 Dec 2026, 20:00', 'from EUR 45.00', 'scheduled']);
    }

    public function test_the_concert_detail_shows_the_seat_grid_with_vip_seats_marked(): void
    {
        $this->schedule();

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSeeInOrder(['The Night Owls', 'VIP', 'EUR 120.00', 'Row A', 'A-1', 'Standard', 'EUR 45.00', 'Row B', 'B-1', 'B-2'], escape: false);
        $response->assertSee('<span class="seat seat--vip" title="' . self::CONCERT_ID . '/A-1">A-1</span>', escape: false);
        $response->assertSee('<span class="seat seat--standard" title="' . self::CONCERT_ID . '/B-1">B-1</span>', escape: false);
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
        $this->commandBus()->send(new MarkSeatsHeld(orderId: $heldOrderId, seatIds: [self::CONCERT_ID . '/A-1']));
        $otherOrderId = $this->commandBus()->send(new StartOrder(customerId: $otherCustomerId, concertId: self::CONCERT_ID));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/account');

        $response->assertOk();
        $response->assertSeeInOrder([$heldOrderId, 'The Night Owls', 'A-1', 'seats_held']);
        $response->assertDontSee($otherOrderId);
    }

    public function test_the_account_without_a_chosen_customer_sends_you_to_whoami(): void
    {
        $this->get('/account')->assertRedirect('/whoami');
    }

    private function register(string $name, string $email): string
    {
        return $this->commandBus()->send(new RegisterCustomer(name: $name, email: $email));
    }

    private function schedule(): void
    {
        $this->app->make(EventBus::class)->publishWithRouting('concert.scheduled', [
            'concertId' => self::CONCERT_ID,
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
