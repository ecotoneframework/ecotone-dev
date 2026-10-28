<?php

declare(strict_types=1);

namespace App\Tests\Web;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 */
final class AdminPagesTest extends WebTestCase
{
    public function test_the_admin_entry_leads_to_the_concerts(): void
    {
        $client = $this->seededClient();

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/concerts');
    }

    public function test_the_venues_page_lists_the_seeded_venue_with_its_seat_count(): void
    {
        $client = $this->seededClient();

        $client->request('GET', '/admin/venues');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table.venues', 'Riverside Hall');
        self::assertSelectorTextContains('table.venues', '72');
    }

    public function test_the_venue_form_creates_a_venue(): void
    {
        $client = $this->seededClient();
        $client->request('GET', '/admin/venues');

        $client->submitForm('Create venue', [
            'name' => 'Harbour Club',
            'sections' => 'Floor',
            'rowsPerSection' => '2',
            'seatsPerRow' => '10',
            'vipRows' => 'A',
        ]);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice', 'Venue "Harbour Club" created.');
        self::assertSelectorTextContains('table.venues', 'Harbour Club');
    }

    public function test_the_concerts_page_lists_the_seeded_concerts_with_their_status_and_venue(): void
    {
        $client = $this->seededClient();

        $client->request('GET', '/admin/concerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'table.concerts tbody tr');
        self::assertSelectorTextContains('table.concerts tbody tr:nth-child(1)', 'Neon Harbour');
        self::assertSelectorTextContains('table.concerts tbody tr:nth-child(1)', 'Riverside Hall');
        self::assertSelectorTextContains('table.concerts tbody tr:nth-child(1)', 'on sale');
        self::assertSelectorTextContains('table.concerts tbody tr:nth-child(3)', 'Aurora Strings');
        self::assertSelectorTextContains('table.concerts tbody tr:nth-child(3)', 'scheduled');
    }

    public function test_the_schedule_form_creates_a_concert(): void
    {
        $client = $this->seededClient();
        $client->request('GET', '/admin/concerts');

        $client->submitForm('Schedule concert', [
            'artist' => 'Velvet Static',
            'startsAt' => '2030-06-01T20:00',
            'standardPriceCents' => '3900',
            'vipPriceCents' => '7900',
            'currency' => 'EUR',
        ]);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice', 'Velvet Static is scheduled.');
        self::assertSelectorCount(4, 'table.concerts tbody tr');
        self::assertSelectorTextContains('table.concerts tbody tr:nth-child(4)', 'Velvet Static');
        self::assertSelectorTextContains('table.concerts tbody tr:nth-child(4)', '39.00 EUR');
    }

    public function test_scheduling_in_the_past_shows_the_refusal_on_the_page(): void
    {
        $client = $this->seededClient();
        $client->request('GET', '/admin/concerts');

        $client->submitForm('Schedule concert', [
            'artist' => 'Velvet Static',
            'startsAt' => '2020-06-01T20:00',
            'standardPriceCents' => '3900',
            'vipPriceCents' => '7900',
            'currency' => 'EUR',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.refusal', 'The concert of Velvet Static cannot be scheduled for 2020-06-01T20:00:00+00:00, because that is in the past');
        self::assertSelectorCount(3, 'table.concerts tbody tr');
    }

    public function test_the_concert_page_shows_its_seats_and_opens_sales(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $client->click($crawler->filter('table.concerts tbody tr:nth-child(3) a')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Aurora Strings');
        self::assertSelectorCount(72, 'table.seats tbody tr');
        self::assertSelectorTextContains('table.seats tbody tr:nth-child(1)', 'A-1');
        self::assertSelectorTextContains('table.seats tbody tr:nth-child(1)', 'vip');

        $client->submitForm('Open sales');
        $client->followRedirect();

        self::assertSelectorTextContains('.notice', 'Sales are open.');
        self::assertSelectorTextContains('.status', 'on sale');
    }

    public function test_opening_sales_of_a_concert_on_sale_shows_the_refusal_on_the_page(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $crawler = $client->click($crawler->filter('table.concerts tbody tr:nth-child(1) a')->link());

        $client->request('POST', $client->getRequest()->getPathInfo() . '/open-sales', ['_token' => $crawler->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.refusal', 'cannot open, because they are already open');
    }

    public function test_a_scheduled_concert_offers_open_sales_as_its_primary_action_and_can_be_cancelled(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $client->click($crawler->filter('table.concerts tbody tr:nth-child(3) a')->link());

        self::assertSelectorExists('form[action$="/open-sales"] button.button-primary:not([disabled])');
        self::assertSelectorNotExists('.toolbar .action-reason');
        self::assertSelectorExists('details.confirm');
    }

    public function test_a_concert_on_sale_shows_open_sales_disabled_with_the_reason_and_can_be_cancelled(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $client->click($crawler->filter('table.concerts tbody tr:nth-child(1) a')->link());

        self::assertSelectorNotExists('form[action$="/open-sales"]');
        self::assertSelectorNotExists('.toolbar .button-primary');
        self::assertSelectorExists('.toolbar button[disabled]');
        self::assertSelectorTextContains('.toolbar .action-reason', 'Sales are already open.');
        self::assertSelectorExists('details.confirm');
    }

    public function test_a_cancelled_concert_shows_open_sales_disabled_with_the_reason_and_offers_no_cancel(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $client->click($crawler->filter('table.concerts tbody tr:nth-child(3) a')->link());
        $client->submitForm('Cancel concert');
        $client->followRedirect();

        self::assertSelectorTextContains('.status', 'cancelled');
        self::assertSelectorNotExists('form[action$="/open-sales"]');
        self::assertSelectorExists('.toolbar button[disabled]');
        self::assertSelectorTextContains('.toolbar .action-reason', 'The concert is cancelled.');
        self::assertSelectorNotExists('details.confirm');
        self::assertSelectorNotExists('form[action$="/cancel"]');
    }

    public function test_the_concert_page_changes_a_tier_price_and_cancels_the_concert(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $client->click($crawler->filter('table.concerts tbody tr:nth-child(1) a')->link());

        $client->submitForm('Change price', ['tier' => 'vip', 'priceCents' => '12000']);
        $client->followRedirect();
        self::assertSelectorTextContains('.notice', 'The vip price is now 120.00 EUR.');
        self::assertSelectorTextContains('.prices', '120.00 EUR');

        $client->submitForm('Cancel concert');
        $client->followRedirect();
        self::assertSelectorTextContains('.notice', 'The concert is cancelled.');
        self::assertSelectorTextContains('.status', 'cancelled');
    }

    public function test_the_navigation_marks_the_page_you_are_on(): void
    {
        $client = $this->seededClient();

        $crawler = $client->request('GET', '/admin/venues');
        self::assertSame('Venues', trim($crawler->filter('nav a[aria-current="page"]')->text()));

        $crawler = $client->click($crawler->filter('nav')->selectLink('Concerts')->link());
        self::assertSame('Concerts', trim($crawler->filter('nav a[aria-current="page"]')->text()));

        $client->click($crawler->filter('table.concerts tbody tr:nth-child(1) a')->link());
        self::assertSame('Concerts', trim($client->getCrawler()->filter('nav a[aria-current="page"]')->text()));
    }

    public function test_a_notice_is_announced_as_a_status_and_a_refusal_as_an_alert(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $crawler = $client->click($crawler->filter('table.concerts tbody tr:nth-child(1) a')->link());

        $client->request('POST', $client->getRequest()->getPathInfo() . '/open-sales', ['_token' => $crawler->filter('input[name="_token"]')->attr('value')]);
        self::assertSelectorExists('.refusal[role="alert"]');

        $client->submitForm('Change price', ['tier' => 'vip', 'priceCents' => '12000']);
        $client->followRedirect();
        self::assertSelectorExists('.notice[role="status"]');
    }

    #[DataProvider('adminForms')]
    public function test_an_admin_form_posted_without_its_token_is_refused(string $path, array $fields): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $concertId = $crawler->filter('table.concerts tbody tr:nth-child(1)')->attr('id');
        $venueId = $crawler->filter('select[name="venueId"] option')->attr('value');

        $client->request('POST', str_replace('{concertId}', $concertId, $path), str_replace('{venueId}', $venueId, $fields));

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('carries no valid CSRF token, so nothing was changed. Every admin form renders its token in the &quot;_token&quot; field: reload the page and submit the form from it again.', $client->getResponse()->getContent());
        $client->request('GET', "/admin/concerts/{$concertId}");
        self::assertSelectorTextContains('.status', 'on sale');
        self::assertSelectorTextContains('.prices', 'VIP 95.00 EUR');
        $client->request('GET', '/admin/concerts');
        self::assertSelectorCount(3, 'table.concerts tbody tr');
        $client->request('GET', '/admin/venues');
        self::assertSelectorCount(1, 'table.venues tbody tr');
    }

    public static function adminForms(): iterable
    {
        yield 'schedule a concert' => ['/admin/concerts', ['venueId' => '{venueId}', 'artist' => 'Velvet Static', 'startsAt' => '2030-06-01T20:00', 'standardPriceCents' => '3900', 'vipPriceCents' => '7900', 'currency' => 'EUR']];
        yield 'open sales' => ['/admin/concerts/{concertId}/open-sales', []];
        yield 'change a tier price' => ['/admin/concerts/{concertId}/price', ['tier' => 'vip', 'priceCents' => '12000']];
        yield 'cancel a concert' => ['/admin/concerts/{concertId}/cancel', []];
        yield 'create a venue' => ['/admin/venues', ['name' => 'Harbour Club', 'sections' => 'Floor', 'rowsPerSection' => '2', 'seatsPerRow' => '10', 'vipRows' => 'A']];
    }

    public function test_an_admin_form_posted_with_a_forged_token_is_refused(): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $concertId = $crawler->filter('table.concerts tbody tr:nth-child(1)')->attr('id');

        $client->request('POST', "/admin/concerts/{$concertId}/cancel", ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $client->request('GET', "/admin/concerts/{$concertId}");
        self::assertSelectorTextContains('.status', 'on sale');
    }

    #[DataProvider('concertActions')]
    public function test_an_unknown_concert_is_not_found_and_named_on_the_concerts_page(string $method, string $path, array $fields): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');
        $unknownConcertId = '0195f0a0-0000-7000-8000-000000000000';

        $client->request($method, str_replace('{concertId}', $unknownConcertId, $path), [...$fields, '_token' => $crawler->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.refusal', "There is no concert {$unknownConcertId}. Pick one from the list below.");
        self::assertSelectorCount(3, 'table.concerts tbody tr');
    }

    #[DataProvider('concertActions')]
    public function test_a_concert_id_that_is_not_a_uuid_is_not_found(string $method, string $path, array $fields): void
    {
        $client = $this->seededClient();
        $crawler = $client->request('GET', '/admin/concerts');

        $client->request($method, str_replace('{concertId}', 'not-a-uuid', $path), [...$fields, '_token' => $crawler->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseStatusCodeSame(404);
    }

    public static function concertActions(): iterable
    {
        yield 'show the concert' => ['GET', '/admin/concerts/{concertId}', []];
        yield 'open sales' => ['POST', '/admin/concerts/{concertId}/open-sales', []];
        yield 'change a tier price' => ['POST', '/admin/concerts/{concertId}/price', ['tier' => 'vip', 'priceCents' => '12000']];
        yield 'cancel the concert' => ['POST', '/admin/concerts/{concertId}/cancel', []];
    }

    private function seededClient(): KernelBrowser
    {
        $client = static::createClient();
        /** @var ManagerRegistry $doctrine */
        $doctrine = static::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, messenger_messages');
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'demo:seed', '--catalogueOnly' => true]), new NullOutput()));

        return $client;
    }
}
