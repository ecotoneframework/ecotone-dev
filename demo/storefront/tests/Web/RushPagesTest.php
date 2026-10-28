<?php

declare(strict_types=1);

namespace Tests\Web;

use App\Customer\RegisterCustomer;
use App\Ordering\MarkSeatHeld;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use App\Rush\StartRush;
use DomainException;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * @internal
 */
final class RushPagesTest extends TestCase
{
    private const CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    private const CUSTOMER_COOKIE = 'storefront_customer';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('rush_buyers')->delete();
        DB::table('rushes')->delete();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
        DB::table('concerts')->delete();
        DB::table('jobs')->delete();
    }

    public function test_a_concert_on_sale_offers_to_simulate_a_rush_of_buyers(): void
    {
        $this->scheduleOnSale();

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSee('<h2>Simulate a rush</h2>', escape: false);
        $response->assertSee('data-start-rush-url="http://localhost/concerts/' . self::CONCERT_ID . '/rushes"', escape: false);
        $response->assertSee('<input type="number" name="buyers" value="30" min="1" max="200">', escape: false);
        $response->assertSee('<input type="number" name="declinedShare" value="30" min="0" max="100">', escape: false);
        $response->assertSee('<input type="number" name="outageShare" value="10" min="0" max="100">', escape: false);
        $response->assertSee('<input type="number" name="bounceShare" value="10" min="0" max="100">', escape: false);
        $response->assertSee('<input type="number" name="seatsPerBuyer" value="2" min="1" max="4">', escape: false);
        $response->assertSee('<script src="http://localhost/js/rush.js"></script>', escape: false);
    }

    public function test_a_concert_not_on_sale_offers_no_rush(): void
    {
        $this->schedule();

        $this->get('/concerts/' . self::CONCERT_ID)->assertDontSee('Simulate a rush');
    }

    public function test_the_order_page_offers_to_simulate_a_rush_on_the_concert_of_the_order(): void
    {
        $this->scheduleOnSale();
        $customerId = $this->app->make(CommandBus::class)->send(new RegisterCustomer(name: 'Ada Lovelace', email: 'ada@example.com'));
        $orderId = $this->app->make(CommandBus::class)->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));

        $response = $this->withCookie(self::CUSTOMER_COOKIE, $customerId)->get('/orders/' . $orderId);

        $response->assertSee('<h2>Simulate a rush</h2>', escape: false);
        $response->assertSee('data-start-rush-url="http://localhost/concerts/' . self::CONCERT_ID . '/rushes"', escape: false);
    }

    public function test_starting_a_rush_answers_with_the_url_of_its_state_which_follows_the_buyers_joining(): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/rushes', ['buyers' => 3, 'declinedShare' => 0, 'outageShare' => 0, 'bounceShare' => 0, 'seatsPerBuyer' => 1]);

        $response->assertAccepted();
        $rushId = $response->json('rushId');
        $response->assertExactJson(['rushId' => $rushId, 'stateUrl' => 'http://localhost/rushes/' . $rushId . '/state.json']);

        Artisan::call('ecotone:run', ['consumerName' => 'storefront_async', '--finishWhenNoMessages' => true]);

        $state = $this->getJson('/rushes/' . $rushId . '/state.json');
        $state->assertOk();
        $state->assertJson(['rushId' => $rushId, 'concertId' => self::CONCERT_ID, 'buyersRequested' => 3, 'seatsPerBuyer' => 1, 'created' => 3, 'paid' => 0, 'failed' => 0, 'finishedAt' => null]);
    }

    public function test_a_rush_started_without_settings_sends_30_buyers_of_2_seats_with_the_default_shares(): void
    {
        $this->scheduleOnSale();

        $rushId = $this->postJson('/concerts/' . self::CONCERT_ID . '/rushes')->json('rushId');
        Artisan::call('ecotone:run', ['consumerName' => 'storefront_async', '--finishWhenNoMessages' => true]);

        $this->getJson('/rushes/' . $rushId . '/state.json')->assertJson(['buyersRequested' => 30, 'seatsPerBuyer' => 2]);
    }

    public function test_a_rush_whose_shares_exceed_100_percent_is_refused_with_the_reason(): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/rushes', ['buyers' => 30, 'declinedShare' => 60, 'outageShare' => 30, 'bounceShare' => 20, 'seatsPerBuyer' => 2]);

        $response->assertUnprocessable();
        $response->assertExactJson(['error' => 'The declined (60 %), outage (30 %) and bounce (20 %) shares of a rush add up to 110 %, but each buyer plays one part, so they cannot exceed 100 %. Lower them until they add up to at most 100 %; the rest of the buyers pay with a card that succeeds.']);
    }

    #[DataProvider('settingsThatAreNotWholeNumbers')]
    public function test_a_rush_setting_that_is_not_a_whole_number_is_refused_naming_the_setting(string $setting, mixed $value): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/rushes', [$setting => $value]);

        $response->assertStatus(422);
        $response->assertExactJson(['error' => sprintf('The %s of a rush is "%s", not a whole number, so the rush cannot start. Give %s as a whole number, or leave it out to use %d.', $setting, $value, $setting, StartRush::DEFAULTS[$setting])]);
    }

    public static function settingsThatAreNotWholeNumbers(): iterable
    {
        yield 'a blank declined share' => ['declinedShare', ''];
        yield 'a word for the outage share' => ['outageShare', 'many'];
        yield 'a fraction of buyers' => ['buyers', '2.5'];
    }

    public function test_a_rush_on_a_concert_not_on_sale_is_refused_naming_its_status(): void
    {
        $this->schedule();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/rushes');

        $response->assertConflict();
        self::assertSame(sprintf('Concert %s is scheduled, not on sale, so a rush cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing and start a new rush, or start the rush on a concert listed as on_sale on /concerts.', self::CONCERT_ID), $response->json('error'));
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_rush_on_a_concert_not_in_the_catalogue_is_not_found(): void
    {
        $this->postJson('/concerts/' . self::CONCERT_ID . '/rushes')->assertNotFound();
    }

    public function test_the_seats_taken_on_a_concert_are_listed_so_the_seat_map_follows_a_rush_before_the_visitor_picks(): void
    {
        $this->scheduleOnSale();
        $customerId = $this->app->make(CommandBus::class)->send(new RegisterCustomer(name: 'Rush buyer 1', email: 'rush-1@example.com'));
        $orderId = $this->app->make(CommandBus::class)->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));
        $this->app->make(CommandBus::class)->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1'));
        $this->app->make(CommandBus::class)->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/B-1', holdId: 'hold-1', expiresAt: '2026-10-25T12:02:00+00:00'));

        $response = $this->getJson('/concerts/' . self::CONCERT_ID . '/taken-seats.json');

        $response->assertOk();
        $response->assertExactJson(['takenSeatIds' => [self::CONCERT_ID . '/B-1']]);
        $adaId = $this->app->make(CommandBus::class)->send(new RegisterCustomer(name: 'Ada Lovelace', email: 'ada@example.com'));
        $this->withCookie(self::CUSTOMER_COOKIE, $adaId)->get('/concerts/' . self::CONCERT_ID)->assertSee('data-taken-seats-url="http://localhost/concerts/' . self::CONCERT_ID . '/taken-seats.json"', escape: false);
    }

    public function test_the_state_of_a_rush_never_started_is_not_found(): void
    {
        $this->getJson('/rushes/0199f3a8-6f2a-7c1e-9a51-000000000000/state.json')->assertNotFound();
        $this->getJson('/rushes/not-a-rush/state.json')->assertNotFound();
    }

    public function test_demo_rush_starts_a_rush_on_the_concert_from_the_console(): void
    {
        $this->scheduleOnSale();

        self::assertSame(0, Artisan::call('demo:rush', ['concertId' => self::CONCERT_ID, '--buyers' => 4, '--seatsPerBuyer' => 1, '--declinedShare' => 25, '--outageShare' => 0, '--bounceShare' => 0]));

        self::assertSame(1, preg_match('/Started rush (?<rushId>[0-9a-f-]{36}) of 4 buyers/', Artisan::output(), $matches));
        Artisan::call('ecotone:run', ['consumerName' => 'storefront_async', '--finishWhenNoMessages' => true]);
        $state = $this->app->make(QueryBus::class)->sendWithRouting('rush.state', $matches['rushId']);
        self::assertSame(4, $state['buyersRequested']);
        self::assertSame(1, $state['seatsPerBuyer']);
        self::assertSame(4, $state['created']);
    }

    public function test_demo_rush_on_a_concert_not_on_sale_is_refused_naming_its_status(): void
    {
        $this->schedule();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Concert %s is scheduled, not on sale, so a rush cannot send buyers to it: ticketing holds seats only while a concert is on sale. Open its sales in ticketing and start a new rush, or start the rush on a concert listed as on_sale on /concerts.', self::CONCERT_ID));

        Artisan::call('demo:rush', ['concertId' => self::CONCERT_ID]);
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
            'venueName' => 'Ecotone Hall',
            'artist' => 'The Night Owls',
            'startsAt' => '2026-12-01T20:00:00+00:00',
            'currency' => 'EUR',
            'tiers' => ['standard' => 4500, 'vip' => 12000],
            'seats' => [
                ['seatId' => self::CONCERT_ID . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip'],
                ['seatId' => self::CONCERT_ID . '/B-1', 'seatCode' => 'B-1', 'tier' => 'standard'],
                ['seatId' => self::CONCERT_ID . '/B-2', 'seatCode' => 'B-2', 'tier' => 'standard'],
            ],
        ], metadata: ['concertId' => self::CONCERT_ID]);
    }
}
