<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\ProblemBoardServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ServicePageScopesTest extends ReleasingWebTestCase
{
    use ProblemBoardServicesTrait;

    public function test_a_single_tenant_service_page_has_no_tenant_switcher(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $crawler = $this->observeBoard($client, '/services/orders');

        self::assertCount(0, $crawler->filter('[data-tenant-switcher]'));
        self::assertSame(['Home', 'orders'], $this->crumbs($crawler));
    }

    public function test_a_single_tenant_service_page_gathers_its_problems_failures_channels_projections_and_traces(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 12400)];
        $this->boardState['orders']->deadLetters = ['total' => 3, 'groups' => [$this->failureGroup('OrderPlaced', 3, 'f-placed')], 'recent' => []];
        $this->boardState['orders']->projections = [$this->projectionReport('totals', eventsBehind: 2)];

        $crawler = $this->observeBoard($client, '/services/orders');

        self::assertSame(['queue-critical', 'projection-behind', 'dead-letters'], $crawler->filter('[data-hub-problems] [data-problem]')->each(static fn (Crawler $problem): string => $problem->attr('data-problem')));
        self::assertSame('3 failures in OrderPlaced', trim($crawler->filter('[data-hub-dead-letters] [data-failure="f-placed"] a')->text()));
        self::assertSame('/dead-letters?service=orders&failureId=f-placed', $crawler->filter('[data-hub-dead-letters] [data-failure="f-placed"] a')->attr('href'));
        self::assertSame('/dead-letters?service=orders', $crawler->filter('[data-open-dead-letters]')->attr('href'));
        self::assertSame('12,400', $crawler->filter('[data-hub-channels] tr[data-channel="invoices"] [data-ready]')->text());
        self::assertSame('/services/orders/projections', $crawler->filter('[data-open-projections]')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-hub-flow-types]'));
    }

    public function test_a_multi_tenant_service_page_offers_every_tenant_in_a_switcher_that_keeps_the_choice_in_the_url(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);

        $crawler = $this->observeBoard($client, '/services/orders');

        self::assertSame(['all', 'tenant_a', 'tenant_b'], $crawler->filter('[data-tenant-switcher] a')->each(static fn (Crawler $link): string => $link->attr('data-tenant-choice')));
        self::assertSame('/services/orders?tenant=tenant_b', $crawler->filter('[data-tenant-choice="tenant_b"]')->attr('href'));
        self::assertSame('page', $crawler->filter('[data-tenant-choice="all"]')->attr('aria-current'));
    }

    public function test_choosing_a_tenant_shows_only_that_tenants_sections_with_links_filtered_to_it(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [$this->channel('invoices', 12400)], 'deadLetters' => ['total' => 3, 'groups' => [$this->failureGroup('OrderPlaced', 3, 'f-placed')], 'recent' => []], 'projections' => [$this->projectionReport('totals', eventsBehind: 2)]],
            'tenant_b' => ['channels' => [$this->channel('invoices', 1)], 'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []], 'projections' => []],
        ];

        $crawler = $this->observeBoard($client, '/services/orders?tenant=tenant_a');

        self::assertSame('tenant_a', $crawler->filter('[data-tenant-choice][aria-current="page"]')->attr('data-tenant-choice'));
        self::assertSame('12,400', $crawler->filter('[data-hub-channels] tr[data-channel="invoices"] [data-ready]')->text());
        self::assertSame('/dead-letters?service=orders@tenant_a', $crawler->filter('[data-open-dead-letters]')->attr('href'));
        self::assertSame('/dead-letters?service=orders@tenant_a&failureId=f-placed', $crawler->filter('[data-failure="f-placed"] a')->attr('href'));
        self::assertSame('/services/orders@tenant_a/projections', $crawler->filter('[data-open-projections]')->attr('href'));
        self::assertSame(['queue-critical', 'projection-behind', 'dead-letters'], $crawler->filter('[data-hub-problems] [data-problem]')->each(static fn (Crawler $problem): string => $problem->attr('data-problem')));
        self::assertSame(['Home', 'orders', 'tenant_a'], $this->crumbs($crawler));
    }

    public function test_the_page_of_one_tenant_does_not_show_the_problems_of_another(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [$this->channel('invoices', 12400)], 'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []], 'projections' => []],
            'tenant_b' => ['channels' => [$this->channel('invoices', 1)], 'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []], 'projections' => []],
        ];

        $crawler = $this->observeBoard($client, '/services/orders?tenant=tenant_b');

        self::assertCount(0, $crawler->filter('[data-hub-problems] [data-problem]'));
        self::assertCount(1, $crawler->filter('[data-hub-problems] [data-all-clear]'));
    }

    public function test_a_tenant_the_service_does_not_advertise_is_refused_naming_the_ones_it_does(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->settleBoard($client);

        $client->request('GET', '/services/orders?tenant=ghost');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Tenant "ghost" is not advertised by service "orders". Choose one of: tenant_a, tenant_b.', (string) $client->getResponse()->getContent());
    }

    public function test_without_a_tenant_a_multi_tenant_service_page_lists_the_problems_of_every_tenant(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [$this->channel('invoices', 12400)], 'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []], 'projections' => []],
            'tenant_b' => ['channels' => [], 'deadLetters' => ['total' => 1, 'groups' => [$this->failureGroup('A', 1)], 'recent' => []], 'projections' => []],
        ];

        $crawler = $this->observeBoard($client, '/services/orders');

        self::assertSame(['queue-critical', 'dead-letters'], $crawler->filter('[data-hub-problems] [data-problem]')->each(static fn (Crawler $problem): string => $problem->attr('data-problem')));
    }

    public function test_the_traces_page_can_be_narrowed_to_one_tenant_of_a_service(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->settleBoard($client);

        $crawler = $client->request('GET', '/traces?service=orders@tenant_b');

        self::assertSame(['orders@tenant_b'], $crawler->filter('[data-service-section]')->each(static fn (Crawler $section): string => $section->attr('data-service-section')));
    }

    /**
     * @return list<string>
     */
    private function crumbs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Breadcrumb"] li')->each(static fn (Crawler $crumb): string => trim($crumb->text()));
    }
}
