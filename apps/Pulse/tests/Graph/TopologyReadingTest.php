<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TopologyReadingTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_a_consistent_topology_opens_with_a_verdict_that_names_what_was_checked(): void
    {
        $crawler = $this->topologyOfOrdersAndBilling();
        $html = (string) $crawler->filter('main')->html();

        $verdict = $crawler->filter('main [data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('ok', $verdict->attr('data-verdict'));
        self::assertSame('No mismatches in 1 command route and 2 event routes.', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertStringContainsString('every command sent over the Distributed Bus has a route to a service that answers Pulse', $verdict->filter('[data-topology-checked]')->text());
        self::assertLessThan(strpos($html, 'data-diagram'), strpos($html, 'data-verdict='));
        self::assertLessThan(strpos($html, 'data-section='), strpos($html, 'data-diagram'));
    }

    public function test_the_page_has_breadcrumbs_that_keep_the_service_scope(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $all = $client->request('GET', '/topology');
        $orders = $client->request('GET', '/topology?service=orders');

        self::assertSame(['Home', 'Topology'], $all->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => $crumb->text()));
        self::assertSame(['Home', 'Services', 'orders', 'Topology'], $orders->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => $crumb->text()));
        self::assertSame('/services/orders', $orders->filter('[data-crumbs] li a')->eq(2)->attr('href'));
    }

    public function test_healthy_routes_are_collapsed_behind_a_count_and_a_state(): void
    {
        $crawler = $this->topologyOfOrdersAndBilling();

        $commands = $crawler->filter('details[data-section="topology.command-routes"]');
        self::assertNull($commands->attr('open'));
        self::assertSame('Command routes', $commands->filter('summary h2')->text());
        self::assertSame('1 · all up to date', $commands->filter('summary [data-summary-state]')->text());
        $events = $crawler->filter('details[data-section="topology.event-routes"]');
        self::assertNull($events->attr('open'));
        self::assertSame('2 · invoice.* · all up to date', $events->filter('summary [data-summary-state]')->text());
        self::assertSame("orders' Service Map sends the events it publishes matching invoice.* to orders over channel {$this->serviceChannels['orders']}.", $crawler->filter('[data-event-subscription="orders"]')->text());
    }

    public function test_the_page_explains_the_distributed_bus_the_service_map_the_request_channel_and_a_mismatch(): void
    {
        $crawler = $this->topologyOfOrdersAndBilling();

        foreach (['distributed-bus', 'service-map', 'request-channel', 'mismatch'] as $term) {
            self::assertGreaterThan(0, $crawler->filter("main [data-concept=\"{$term}\"]")->count(), $term);
        }
        self::assertStringContainsString('Flow graph', $crawler->filter('[data-topology-reading]')->text());
    }

    public function test_mismatches_come_first_as_problem_cards_saying_where_why_and_what_to_change(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersWithMismatches(), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/topology');
        $html = (string) $crawler->filter('main')->html();

        self::assertSame('critical', $crawler->filter('main [data-verdict]')->attr('data-verdict'));
        self::assertSame('4 mismatches between what the services send and where their Service Maps route it.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertLessThan(strpos($html, 'data-diagram'), strpos($html, 'data-mismatch='));
        self::assertCount(3, $crawler->filter('[data-topology-mismatches] > [data-mismatch]'));
        self::assertCount(1, $crawler->filter('details[data-topology-more-mismatches] [data-mismatch]'));
        self::assertSame('1 more mismatch', $crawler->filter('details[data-topology-more-mismatches] > summary')->text());
        $first = $crawler->filter('[data-mismatch]')->first();
        self::assertSame('unmapped-command-target', $first->attr('data-mismatch'));
        self::assertSame('critical', $first->filter('[data-problem]')->attr('data-problem-severity'));
        self::assertSame(['orders', 'billing'], $first->filter('[data-problem-where] li')->each(static fn (Crawler $place): string => $place->text()));
        self::assertStringContainsString('UnknownDistributedDestination', $first->filter('[data-problem-evidence]')->text());
        self::assertCount(1, $first->filter('.pri'));
        self::assertSame('/flow?service=orders', $first->filter('.pri')->attr('href'));
        $crawler->filter('[data-mismatch]')->each(static function (Crawler $mismatch): void {
            self::assertCount(1, $mismatch->filter('.pri'));
            self::assertCount(1, $mismatch->filter('[data-problem-fix]'));
        });
    }

    public function test_each_mismatch_leads_to_the_place_where_it_is_fixed(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersWithMismatches(), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/topology');

        self::assertSame('/connect?service=shipping', $crawler->filter('[data-mismatch="unconfigured-command-target"] .pri')->attr('href'));
        self::assertSame('/flow?find=order.placed', $crawler->filter('[data-mismatch="unsubscribed-event"] .pri')->attr('href'));
        self::assertStringStartsWith('https://docs.ecotone.tech/', (string) $crawler->filter('[data-mismatch="unpublished-subscription"] .pri')->attr('href'));
    }

    public function test_a_command_route_to_a_service_that_stopped_answering_points_at_diagnosing_it(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling()]);
        $this->advanceTimeBy(20);

        $crawler = $client->request('GET', '/topology');

        self::assertSame('warning', $crawler->filter('main [data-verdict]')->attr('data-verdict'));
        self::assertSame('/services/billing', $crawler->filter('[data-mismatch="silent-command-target"] .pri')->attr('href'));
        self::assertCount(1, $crawler->filter('main [data-verdict] [data-notice="incomplete"]'));
    }

    public function test_services_without_a_service_map_get_an_empty_state_showing_how_to_add_one(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->serviceHandlingCommandX('orders'), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/topology');

        self::assertSame('ok', $crawler->filter('main [data-verdict]')->attr('data-verdict'));
        $empty = $crawler->filter('[data-empty="nothing-yet"]');
        self::assertStringContainsString('DistributedServiceMap', $empty->text());
        self::assertStringContainsString("->withCommandMapping('billing', 'billing')", $empty->filter('[data-empty-snippet] code')->text());
        self::assertCount(0, $crawler->filter('[data-diagram]'));
        self::assertCount(0, $crawler->filter('details[data-section]'));
    }

    private function topologyOfOrdersAndBilling(): Crawler
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        return $client->request('GET', '/topology');
    }
}
