<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\ProblemBoardServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ServicesComparisonTest extends ReleasingWebTestCase
{
    use ProblemBoardServicesTrait;

    public function test_the_page_opens_with_one_verdict_on_connection_and_versions(): void
    {
        $client = $this->boardWith(['orders' => null, 'billing' => null]);

        $crawler = $this->observeBoard($client, '/services');

        self::assertCount(1, $crawler->filter('[data-verdict]'));
        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('2 services, both connected; Ecotone versions match.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertSame('2', $crawler->filter('[data-verdict-meta] [data-summary="connected"] b')->text());
        self::assertCount(0, $crawler->filter('[data-problem]'));
        self::assertCount(0, $crawler->filter('.strip'));
    }

    public function test_a_service_behind_on_ecotone_is_named_in_the_verdict_and_flagged_on_its_row(): void
    {
        $client = $this->boardWith(['orders' => null, 'billing' => null]);
        $this->boardState['billing']->version = '2.1.0';

        $crawler = $this->observeBoard($client, '/services');

        self::assertSame('warning', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('2 services, both connected; orders runs an older Ecotone than 2.1.0.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertStringContainsString('older than 2.1.0', $crawler->filter('tr[data-service="orders"] [data-drifted]')->attr('title'));
    }

    public function test_the_verdict_names_the_services_that_need_attention(): void
    {
        $client = $this->boardWith(['orders' => null, 'billing' => null]);
        $this->boardState['orders']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('order.place', 1)], 'recent' => []];

        $crawler = $this->observeBoard($client, '/services');

        self::assertSame('warning', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('2 services, both connected; Ecotone versions match; orders needs attention.', $crawler->filter('[data-verdict-sentence]')->text());
    }

    public function test_rows_are_sorted_worst_first(): void
    {
        $client = $this->boardWith(['alpha' => null, 'bravo' => null, 'charlie' => null]);
        $this->boardState['bravo']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('order.place', 1)], 'recent' => []];
        $this->boardState['charlie']->channels = [$this->channel('invoices', 20000)];

        $crawler = $this->observeBoard($client, '/services');

        self::assertSame(['charlie', 'bravo', 'alpha'], $crawler->filter('table tbody tr[data-service]')->each(static fn (Crawler $row): string => $row->attr('data-service')));
        self::assertSame(['critical', 'warning', 'ok'], $crawler->filter('table tbody tr[data-service]')->each(static fn (Crawler $row): string => $row->attr('data-row-status')));
    }

    public function test_every_count_of_a_row_links_to_its_filtered_page(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->deadLetters = ['total' => 3, 'groups' => [$this->failureGroup('order.place', 2), $this->failureGroup('order.cancel', 1, 'failure-2')], 'recent' => []];
        $this->boardState['orders']->channels = [$this->channel('invoices', 20000), $this->channel('emails', 0)];
        $this->boardState['orders']->projections = [$this->projectionReport('orders_projection', eventsBehind: 4), $this->projectionReport('stock_projection')];

        $row = $this->observeBoard($client, '/services')->filter('tr[data-service="orders"]');

        $failed = $row->filter('td[data-column="failed-messages"] a[data-failed-messages]');
        self::assertSame('3 failed messages', $failed->text());
        self::assertSame('/dead-letters?service=orders', $failed->attr('href'));
        self::assertSame('1 of 2 over their threshold', $this->squashed($row->filter('td[data-column="channels"]')));
        self::assertSame('/services/orders/channels', $row->filter('td[data-column="channels"] a')->attr('href'));
        self::assertSame('1 of 2 behind', $this->squashed($row->filter('td[data-column="projections"]')));
        self::assertSame('/services/orders/projections', $row->filter('td[data-column="projections"] a')->attr('href'));
    }

    public function test_a_healthy_row_is_quiet(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('emails', 0)];
        $this->boardState['orders']->projections = [$this->projectionReport('orders_projection')];

        $row = $this->observeBoard($client, '/services')->filter('tr[data-service="orders"]');

        self::assertSame('ok', $row->attr('data-row-status'));
        self::assertSame('none', $this->squashed($row->filter('td[data-column="failed-messages"]')));
        self::assertSame('1 channel · none stuck', $this->squashed($row->filter('td[data-column="channels"]')));
        self::assertSame('1 · up to date', $this->squashed($row->filter('td[data-column="projections"]')));
        self::assertCount(0, $row->filter('[data-dead-letters]'));
        self::assertCount(0, $row->filter('[data-channels-over]'));
    }

    public function test_licence_and_request_channel_are_shown_on_demand(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $details = $this->observeBoard($client, '/services')->filter('tr[data-service="orders"] details[data-row-details]');

        self::assertNull($details->attr('open'));
        self::assertStringContainsString('licence', $details->filter('summary')->text());
        self::assertSame($this->serviceChannels['orders'], $details->filter('[data-request-channel]')->text());
        self::assertCount(1, $details->filter('[data-licence]'));
    }

    public function test_the_tenants_of_a_service_are_collapsed_under_a_count_and_their_state(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [], 'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []], 'projections' => []],
            'tenant_b' => ['channels' => [], 'deadLetters' => ['total' => 2, 'groups' => [$this->failureGroup('order.place', 2)], 'recent' => []], 'projections' => []],
        ];

        $row = $this->observeBoard($client, '/services')->filter('tr[data-service="orders"]');

        $tenants = $row->filter('details[data-tenants-disclosure]');
        self::assertSame('2 tenants · all connected', $this->squashed($tenants->filter('summary')));
        self::assertSame(['tenant_b', 'tenant_a'], $tenants->filter('[data-tenants] [data-tenant]')->each(static fn (Crawler $tenant): string => $tenant->attr('data-tenant')));
        self::assertSame('2 failed messages', $row->filter('a[data-failed-messages]')->text());
        self::assertSame('warning', $row->attr('data-row-status'));
    }

    public function test_the_page_offers_to_connect_another_service_and_leads_back_home(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $crawler = $this->observeBoard($client, '/services');

        self::assertSame('+ Connect a service', $crawler->filter('main [data-connect-service]')->text());
        self::assertSame('/connect', $crawler->filter('main [data-connect-service]')->attr('href'));
        self::assertSame(['Home', 'Services'], $crawler->filter('nav[aria-label="Breadcrumb"] li')->each(static fn (Crawler $crumb): string => trim($crumb->text())));
    }

    public function test_each_column_explains_its_concept_on_first_use(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $head = $this->observeBoard($client, '/services')->filter('table thead');

        foreach (['snapshot', 'channel', 'dead-letter', 'projection'] as $term) {
            self::assertCount(1, $head->filter(sprintf('[data-concept="%s"]', $term)), $term);
        }
    }

    private function squashed(Crawler $cell): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $cell->text()));
    }
}
