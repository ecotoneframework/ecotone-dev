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
final class FlowGraphCollapseTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_a_collapsed_service_is_drawn_as_one_node_carrying_its_distributed_arrows(): void
    {
        $client = $this->ordersAndBilling();

        $diagram = MermaidDiagram::fromPage($client->request('GET', '/flow?collapse[]=billing'));

        self::assertSame(['billing<br/>6 nodes, collapsed'], array_values(array_column(array_filter($diagram->nodes, fn (array $node) => $node['service'] === 'billing'), 'label')));
        self::assertGreaterThan(1, $diagram->nodesOf('orders'));
        self::assertTrue($diagram->hasEdge('orders', 'invoice.create', 'distributed command', 'billing', 'collapsed'));
        self::assertTrue($diagram->hasEdge('billing', 'collapsed', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_each_service_has_a_link_collapsing_it_and_a_collapsed_service_links_back_to_its_detail(): void
    {
        $client = $this->ordersAndBilling();

        $page = $client->request('GET', '/flow');
        self::assertSame('/flow?collapse%5B0%5D=billing', $page->filter('[data-collapse="billing"]')->attr('href'));

        $collapsed = $client->request('GET', '/flow?collapse[]=billing');
        self::assertCount(0, $collapsed->filter('[data-collapse="billing"]'));
        self::assertSame('/flow', $collapsed->filter('[data-expand="billing"]')->attr('href'));
        $nodes = json_decode($collapsed->filter('pre.mermaid')->attr('data-graph-nodes'), true, flags: JSON_THROW_ON_ERROR);
        $collapsedNode = array_values(array_filter($nodes, fn (array $node) => $node['service'] === 'billing' && str_contains($node['title'], 'collapsed')));
        self::assertSame('/flow', $collapsedNode[0]['href']);
    }

    public function test_collapsing_a_service_opens_the_detail_where_the_service_overview_would_open(): void
    {
        $others = ['svc01', 'svc02', 'svc03', 'svc04', 'svc05'];
        $this->configurePulseWithServices('orders', 'billing', ...$others);
        $client = $this->pulseWebClient();
        $services = ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()];
        foreach ($others as $other) {
            $services[$other] = $this->serviceHandlingCommandX($other);
        }
        $this->pulseHavingHeardFrom($client, $services);
        self::assertCount(1, $client->request('GET', '/flow')->filter('[data-flow-overview]'));

        $page = $client->request('GET', '/flow?' . http_build_query(['collapse' => $others]));

        self::assertCount(0, $page->filter('[data-flow-overview]'));
        $diagram = MermaidDiagram::fromPage($page);
        self::assertSame(1, $diagram->nodesOf('svc01'));
        self::assertGreaterThan(1, $diagram->nodesOf('orders'));
    }

    private function ordersAndBilling(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        return $client;
    }
}
