<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\Diagram;
use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
final class FlowGraphNavigationTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_above_six_services_the_graph_opens_as_an_overview_with_a_node_per_service_and_the_distributed_edges_between_them(): void
    {
        $client = $this->fifteenServices();

        $crawler = $client->request('GET', '/flow');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-flow-overview]'));
        $diagram = Diagram::fromPage($crawler);
        self::assertSame([], $diagram->services);
        self::assertCount(15, $diagram->nodes);
        self::assertSame(['service'], array_values(array_unique(array_column($diagram->nodes, 'kind'))));
        self::assertCount(15, array_filter(array_keys($diagram->nodes), fn (string $id) => preg_match('/^s\d+$/', $id) === 1));
        $thick = array_column(array_filter($diagram->edges, fn (array $edge) => $edge['arrow'] === Diagram::THICK), 'label');
        self::assertNotEmpty(preg_grep('/^\d+ commands?$/', $thick));
        self::assertNotEmpty(preg_grep('/^\d+ events?$/', $thick));
    }

    public function test_an_overview_node_drills_down_to_that_service_alone(): void
    {
        $client = $this->fifteenServices();

        $crawler = $client->request('GET', '/flow');

        $hrefs = array_column(Diagram::fromPage($crawler)->nodes, 'href');
        self::assertContains('/flow?services%5B0%5D=orders', $hrefs);
        $drilled = $client->request('GET', '/flow?services[]=orders');
        $diagram = Diagram::fromPage($drilled);
        self::assertCount(0, $drilled->filter('[data-flow-overview]'));
        self::assertSame(['orders'], array_values(array_unique(array_column($diagram->nodes, 'service'))));
        self::assertLessThanOrEqual(static::getContainer()->getParameter('pulse.graph_default_node_limit'), count($diagram->nodes));
    }

    public function test_the_overview_can_be_asked_for_with_fewer_services_and_the_detail_with_more(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $overview = $client->request('GET', '/flow?view=overview');
        $detail = $client->request('GET', '/flow');

        self::assertCount(1, $overview->filter('[data-flow-overview]'));
        self::assertSame([], Diagram::fromPage($overview)->services);
        self::assertCount(0, $detail->filter('[data-flow-overview]'));
        self::assertEqualsCanonicalizing(['orders', 'billing'], Diagram::fromPage($detail)->services);
    }

    public function test_node_labels_are_short_and_the_full_text_travels_with_the_graph_as_the_title(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        $diagram = Diagram::fromPage($crawler);
        self::assertNotEmpty($diagram->nodes);
        foreach ($diagram->nodes as $node) {
            self::assertNotNull($node['title']);
            self::assertDoesNotMatchRegularExpression('#[/\\\\]#', $node['label']);
        }
        $handler = array_values(array_filter($diagram->nodes, fn (array $node) => str_contains((string) $node['title'], '::')));
        self::assertNotEmpty($handler);
        self::assertStringContainsString('class@anonymous', (string) $handler[0]['title']);
    }

    public function test_nodes_link_to_their_focus_and_the_legend_lists_each_service_with_its_dead_letters_and_traces(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        $diagram = Diagram::fromPage($crawler);
        foreach ($diagram->nodes as $node) {
            self::assertStringStartsWith('/flow?focus=' . $node['service'] . ':', (string) $node['href']);
        }
        self::assertSame('/dead-letters?service=billing', $crawler->filter('[data-service-link="billing"] a[data-dead-letters]')->attr('href'));
        self::assertSame('/traces?service=billing', $crawler->filter('[data-service-link="billing"] a[data-traces]')->attr('href'));
    }

    public function test_the_legend_explains_the_colours_and_arrows(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        foreach (['passed', 'pending', 'failed', 'asynchronous', 'inferred', 'crossed'] as $entry) {
            self::assertCount(1, $crawler->filter('[data-graph-legend] [data-legend="' . $entry . '"]'));
        }
    }

    private function fifteenServices(): KernelBrowser
    {
        $others = [];
        for ($index = 1; $index <= 13; ++$index) {
            $others[sprintf('svc%02d', $index)] = null;
        }
        $this->configurePulseWithServices('orders', 'billing', ...array_keys($others));
        $client = $this->pulseWebClient();
        $services = ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()];
        foreach (array_keys($others) as $name) {
            $services[$name] = $this->serviceHandlingCommandX($name);
        }
        $this->pulseHavingHeardFrom($client, $services);

        return $client;
    }
}
