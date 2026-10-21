<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\MermaidDiagram;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
final class ServiceScopeFilterTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_the_flow_graph_of_one_service_shows_it_with_the_services_it_talks_to_greyed_and_leaves_the_others_out(): void
    {
        $client = $this->pulseWithOrdersBillingAndShipping();

        $crawler = $client->request('GET', '/flow?service=orders');

        $diagram = MermaidDiagram::fromPage($crawler);
        self::assertGreaterThan(0, $diagram->nodesOf('orders'));
        self::assertGreaterThan(0, $diagram->nodesOf('billing'));
        self::assertSame(0, $diagram->nodesOf('shipping'));
        self::assertStringContainsString('classDef greyed', $diagram->source);
        self::assertStringContainsString('greyed: billing', $crawler->filter('[data-scope-note]')->text());
        self::assertSame('orders', $crawler->filter('[data-scope-filter] option[selected]')->attr('value'));
    }

    public function test_the_flow_graph_keeps_the_full_graph_one_click_away(): void
    {
        $client = $this->pulseWithOrdersBillingAndShipping();

        $crawler = $client->request('GET', '/flow?service=orders');
        $full = $client->click($crawler->filter('[data-show-all-services]')->link());

        self::assertSame('/flow', parse_url($client->getRequest()->getUri(), PHP_URL_PATH));
        self::assertGreaterThan(0, MermaidDiagram::fromPage($full)->nodesOf('shipping'));
        self::assertCount(0, $full->filter('[data-scope-note]'));
    }

    public function test_a_service_that_talks_to_nobody_is_shown_alone(): void
    {
        $client = $this->pulseWithOrdersBillingAndShipping();

        $crawler = $client->request('GET', '/flow?service=shipping');

        $diagram = MermaidDiagram::fromPage($crawler);
        self::assertGreaterThan(0, $diagram->nodesOf('shipping'));
        self::assertSame(0, $diagram->nodesOf('orders'));
        self::assertStringContainsString('talks to no other service', $crawler->filter('[data-scope-note]')->text());
    }

    public function test_a_service_filter_naming_an_unknown_service_is_refused_with_the_services_to_choose_from(): void
    {
        $client = $this->pulseWithOrdersBillingAndShipping();

        foreach (['/flow', '/topology', '/catalogue'] as $view) {
            $client->request('GET', $view . '?service=ghost');

            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString('Service "ghost" is not among the services that answered. Choose one of: billing, orders, shipping, or remove the service parameter to see all services.', (string) $client->getResponse()->getContent());
        }
    }

    public function test_the_topology_of_one_service_lists_only_the_routes_that_touch_it(): void
    {
        $client = $this->pulseWithOrdersBillingAndShipping();

        $all = $client->request('GET', '/topology');
        $orders = $client->request('GET', '/topology?service=orders');
        $shipping = $client->request('GET', '/topology?service=shipping');

        self::assertCount(1, $all->filter('[data-command-route="orders>billing"]'));
        self::assertCount(1, $orders->filter('[data-command-route="orders>billing"]'));
        self::assertCount(0, $shipping->filter('[data-command-route]'));
        self::assertStringContainsString('class', $orders->filter('[data-topology]')->text());
        self::assertSame('/topology', $orders->filter('[data-show-all-services]')->attr('href'));
    }

    public function test_the_topology_of_one_service_lists_only_the_mismatches_that_concern_it(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersWithMismatches(), 'billing' => $this->serviceHandlingCommandX('billing'), 'shipping' => $this->serviceHandlingCommandX('shipping')]);

        $everything = $client->request('GET', '/topology');
        $shipping = $client->request('GET', '/topology?service=shipping');
        $orders = $client->request('GET', '/topology?service=orders');

        self::assertGreaterThan(0, $everything->filter('[data-mismatch]')->count());
        self::assertCount(0, $shipping->filter('[data-mismatch]'));
        self::assertSame($everything->filter('[data-mismatch]')->count(), $orders->filter('[data-mismatch]')->count());
    }

    public function test_the_catalogue_of_one_service_greys_the_services_it_talks_to_and_leaves_the_others_out(): void
    {
        $client = $this->pulseWithOrdersBillingAndShipping();

        $crawler = $client->request('GET', '/catalogue?service=orders');

        self::assertCount(0, $crawler->filter('[data-group][data-service="orders"][data-greyed]'));
        self::assertGreaterThan(0, $crawler->filter('[data-group][data-service="billing"][data-greyed]')->count());
        self::assertCount(0, $crawler->filter('[data-group][data-service="shipping"]'));
        self::assertSame('/catalogue', $crawler->filter('[data-show-all-services]')->attr('href'));
    }

    public function test_the_catalogue_search_keeps_the_service_filter(): void
    {
        $client = $this->pulseWithOrdersBillingAndShipping();

        $crawler = $client->request('GET', '/catalogue?service=orders');

        self::assertSame('orders', $crawler->filter('[data-catalogue-search] input[name="service"]')->attr('value'));
    }

    private function pulseWithOrdersBillingAndShipping(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices(), 'shipping' => $this->serviceHandlingCommandX('shipping')]);

        return $client;
    }
}
