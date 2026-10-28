<?php

declare(strict_types=1);

namespace Tests\Web;

use App\Ordering\MarkSeatHeld;
use App\Ordering\RecordSeatRefusal;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @internal
 */
final class ContentionPagesTest extends TestCase
{
    private const CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    private const SEAT_ID = self::CONCERT_ID . '/B-1';
    private const REFUSAL = 'Seat B-1 is held by another order';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('contention_buyers')->delete();
        DB::table('contentions')->delete();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
        DB::table('concerts')->delete();
        DB::table('jobs')->delete();
    }

    public function test_a_concert_on_sale_offers_to_contend_for_one_of_its_seats_with_5_buyers_by_default(): void
    {
        $this->scheduleOnSale();

        $response = $this->get('/concerts/' . self::CONCERT_ID);

        $response->assertOk();
        $response->assertSee('<h2>Contend for one seat</h2>', escape: false);
        $response->assertSee('data-start-contention-url="http://localhost/concerts/' . self::CONCERT_ID . '/contentions"', escape: false);
        $response->assertSeeInOrder(['<select name="seatId">', '<option value="' . self::CONCERT_ID . '/A-1">A-1</option>', '<option value="' . self::CONCERT_ID . '/B-1">B-1</option>', '<option value="' . self::CONCERT_ID . '/B-2">B-2</option>', '</select>'], escape: false);
        $response->assertSee('<input type="number" name="buyers" value="5" min="2" max="10">', escape: false);
        $response->assertSee('Contend for this seat with <span data-contention-buyers-count>5</span> buyers', escape: false);
        $response->assertSee('<script src="http://localhost/js/contention.js"></script>', escape: false);
    }

    public function test_a_concert_not_on_sale_offers_no_contention(): void
    {
        $this->schedule();

        $this->get('/concerts/' . self::CONCERT_ID)->assertDontSee('Contend for one seat');
    }

    public function test_starting_a_contention_answers_with_its_page_and_queues_one_hold_request_per_buyer(): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/contentions', ['seatId' => self::SEAT_ID, 'buyers' => 3]);

        $response->assertCreated();
        $contentionId = $response->json('contentionId');
        $response->assertExactJson(['contentionId' => $contentionId, 'pageUrl' => 'http://localhost/contentions/' . $contentionId, 'stateUrl' => 'http://localhost/contentions/' . $contentionId . '/state.json']);
        $state = $this->getJson('/contentions/' . $contentionId . '/state.json');
        $state->assertOk();
        $state->assertJson(['contentionId' => $contentionId, 'seatId' => self::SEAT_ID, 'seatCode' => 'B-1', 'buyersRequested' => 3]);
        self::assertSame(['pending', 'pending', 'pending'], array_column($state->json('buyers'), 'state'));
        self::assertSame(3, DB::table('jobs')->count());
    }

    public function test_the_contention_page_shows_one_buyer_green_and_every_other_one_red_with_the_reason_of_ticketing(): void
    {
        $this->scheduleOnSale();
        $contentionId = $this->postJson('/concerts/' . self::CONCERT_ID . '/contentions', ['seatId' => self::SEAT_ID, 'buyers' => 5])->json('contentionId');
        $buyers = $this->getJson('/contentions/' . $contentionId . '/state.json')->json('buyers');
        foreach (array_slice($buyers, 0, 4) as $buyer) {
            $this->app->make(CommandBus::class)->send(new RecordSeatRefusal(orderId: $buyer['orderId'], seatId: self::SEAT_ID, holdId: $buyer['holdId'], reason: self::REFUSAL));
        }
        $this->app->make(CommandBus::class)->send(new MarkSeatHeld(orderId: $buyers[4]['orderId'], seatId: self::SEAT_ID, holdId: $buyers[4]['holdId'], expiresAt: '2026-10-25T12:02:00+00:00'));

        $response = $this->get('/contentions/' . $contentionId);

        $response->assertOk();
        $response->assertSee('One seat, 5 buyers, one hold');
        $response->assertSee('the seat is a consistency boundary');
        $response->assertSee('data-state-url="http://localhost/contentions/' . $contentionId . '/state.json"', escape: false);
        self::assertSame(1, substr_count($response->getContent(), 'contention__buyer--held'));
        self::assertSame(4, substr_count($response->getContent(), 'contention__buyer--refused'));
        self::assertSame(4, substr_count($response->getContent(), self::REFUSAL));
        $response->assertSeeInOrder(['Contending buyer 4', 'refused', self::REFUSAL, 'Contending buyer 5', 'held'], escape: false);
        $response->assertSee('<input type="hidden" name="customerId" value="' . $buyers[4]['customerId'] . '">', escape: false);
        $response->assertSee('a normal held order');
    }

    public function test_a_contention_of_more_than_10_buyers_is_refused_with_the_bounds(): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/contentions', ['seatId' => self::SEAT_ID, 'buyers' => 11]);

        $response->assertUnprocessable();
        $response->assertExactJson(['error' => 'A contention of 11 buyers cannot start: a contention sends 2 to 10 buyers after one seat. Ask for between 2 and 10 buyers.']);
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_contention_whose_buyers_are_not_a_whole_number_is_refused(): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/contentions', ['seatId' => self::SEAT_ID, 'buyers' => 'many']);

        $response->assertUnprocessable();
        $response->assertExactJson(['error' => 'The buyers of a contention is "many", not a whole number, so the contention cannot start. Give buyers as a whole number between 2 and 10, or leave it out to send 5.']);
    }

    public function test_a_contention_for_a_seat_the_concert_does_not_have_is_refused_naming_the_seat(): void
    {
        $this->scheduleOnSale();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/contentions', ['seatId' => self::CONCERT_ID . '/Z-9']);

        $response->assertConflict();
        $response->assertExactJson(['error' => sprintf('Concert %s has no seat %s/Z-9, so a contention cannot send buyers after it. Pick one of the seats on the concert page.', self::CONCERT_ID, self::CONCERT_ID)]);
    }

    public function test_a_contention_on_a_concert_not_on_sale_is_refused_naming_its_status(): void
    {
        $this->schedule();

        $response = $this->postJson('/concerts/' . self::CONCERT_ID . '/contentions', ['seatId' => self::SEAT_ID]);

        $response->assertConflict();
        self::assertStringStartsWith(sprintf('Concert %s is scheduled, not on sale, so a contention cannot send buyers to it', self::CONCERT_ID), $response->json('error'));
    }

    public function test_a_contention_never_started_is_not_found(): void
    {
        $this->get('/contentions/0199f3a8-6f2a-7c1e-9a51-000000000000')->assertNotFound();
        $this->getJson('/contentions/0199f3a8-6f2a-7c1e-9a51-000000000000/state.json')->assertNotFound();
        $this->getJson('/contentions/not-a-contention/state.json')->assertNotFound();
        $this->postJson('/concerts/0199f3a8-6f2a-7c1e-9a51-000000000000/contentions', ['seatId' => self::SEAT_ID])->assertNotFound();
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
                ['seatId' => self::CONCERT_ID . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip'],
                ['seatId' => self::CONCERT_ID . '/B-1', 'seatCode' => 'B-1', 'tier' => 'standard'],
                ['seatId' => self::CONCERT_ID . '/B-2', 'seatCode' => 'B-2', 'tier' => 'standard'],
            ],
        ], metadata: ['concertId' => self::CONCERT_ID]);
    }
}
