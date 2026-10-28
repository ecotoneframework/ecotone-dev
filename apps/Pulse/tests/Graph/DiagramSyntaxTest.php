<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\MermaidDiagram;
use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
final class DiagramSyntaxTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    private const INVOICED = '22222222222222222222222222222222';

    public function test_every_service_subgraph_of_the_flow_graph_is_closed(): void
    {
        $diagram = $this->diagramOf('/flow?services[]=orders&services[]=billing&services[]=shipping', 'flow-three-services');

        self::assertSame(3, substr_count($diagram, '    subgraph '));
        self::assertSame(3, substr_count($diagram, "\n    end\n"));
        $parsed = new MermaidDiagram($diagram);
        foreach (['orders', 'billing', 'shipping'] as $service) {
            self::assertGreaterThan(0, $parsed->nodesOf($service));
        }
        self::assertTrue($parsed->hasEdge('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
    }

    public function test_every_service_subgraph_of_a_two_service_flow_graph_is_closed(): void
    {
        $diagram = $this->diagramOf('/flow?services[]=orders&services[]=billing', 'flow-two-services');

        self::assertSame(2, substr_count($diagram, '    subgraph '));
        self::assertSame(2, substr_count($diagram, "\n    end\n"));
        $parsed = new MermaidDiagram($diagram);
        self::assertGreaterThan(0, $parsed->nodesOf('orders'));
        self::assertGreaterThan(0, $parsed->nodesOf('billing'));
        self::assertSame(0, $parsed->nodesOf('shipping'));
        self::assertTrue($parsed->hasEdge('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
    }

    public function test_every_service_subgraph_of_the_flow_graph_highlighting_a_trace_is_closed(): void
    {
        $diagram = $this->diagramOf('/flow?trace=' . self::INVOICED, 'flow-trace');

        self::assertSame(2, substr_count($diagram, '    subgraph '));
        self::assertSame(2, substr_count($diagram, "\n    end\n"));
        $parsed = new MermaidDiagram($diagram);
        self::assertTrue($parsed->hasEdge('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
        self::assertStringContainsString('class ', $diagram);
        self::assertStringContainsString('tracePassed', $diagram);
    }

    public function test_the_service_overview_diagram_connects_every_service_without_subgraphs(): void
    {
        $diagram = $this->diagramOf('/flow?view=overview', 'flow-overview');

        self::assertStringNotContainsString('subgraph', $diagram);
        self::assertSame(3, preg_match_all('/^    s\d+\["[^"]*"\]$/m', $diagram));
        self::assertMatchesRegularExpression('/^    s\d+ ==>\|"2 commands"\| s\d+$/m', $diagram);
    }

    public function test_the_flow_graph_with_a_collapsed_service_and_folded_channels_highlighting_a_trace_keeps_every_subgraph_closed(): void
    {
        $diagram = $this->diagramOf('/flow?trace=' . self::INVOICED . '&collapse[]=billing&fold=channels', 'flow-collapsed-folded');

        self::assertSame(substr_count($diagram, '    subgraph '), substr_count($diagram, "\n    end\n"));
        self::assertMatchesRegularExpression('/^        s\d+c\["billing<br\/>\d+ nodes, collapsed"\]$/m', $diagram);
        self::assertMatchesRegularExpression('/==>\|"async: orders_async"\|/', $diagram);
    }

    public function test_the_flow_graph_following_a_node_keeps_every_subgraph_closed(): void
    {
        $client = $this->pulseWithServices();
        $this->diagramOf('/flow?trace=' . self::INVOICED, 'flow-trace', $client);
        $nodes = json_decode($client->getCrawler()->filter('pre.mermaid')->attr('data-graph-nodes'), true, flags: JSON_THROW_ON_ERROR);
        $invoice = array_values(array_filter($nodes, fn (array $node) => $node['service'] === 'billing' && $node['title'] === 'invoice.create'));

        $diagram = $this->diagramOf($invoice[0]['href'], 'flow-focus', $client);

        self::assertSame(substr_count($diagram, '    subgraph '), substr_count($diagram, "\n    end\n"));
        self::assertStringContainsString('    classDef focused stroke-width:4px', $diagram);
        self::assertMatchesRegularExpression('/^    class s\d+n\d+ focused$/m', $diagram);
    }

    public function test_topology_diagram_connects_the_services_that_send_commands(): void
    {
        $diagram = $this->diagramOf('/topology', 'topology');

        self::assertStringStartsWith('graph LR', $diagram);
        self::assertMatchesRegularExpression('/^    t0 -->\|"[^"]+"\| t1$/m', $diagram);
        self::assertStringContainsString('["orders"]', $diagram);
        self::assertStringContainsString('["billing"]', $diagram);
    }

    private function diagramOf(string $uri, string $name, ?KernelBrowser $client = null): string
    {
        $client ??= $this->pulseWithServices();
        $client->request('GET', $uri);
        foreach ($this->tracedServices as $service => $application) {
            $this->runService($application, $service);
        }
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->request('GET', $uri);
        $diagram = $crawler->filter('pre.mermaid')->getNode(0)->textContent;

        $directory = dirname(__DIR__, 2) . '/var/mermaid';
        is_dir($directory) || mkdir($directory, 0777, true);
        file_put_contents($directory . '/' . $name . '.mmd', $diagram);

        return $diagram;
    }

    private function pulseWithServices(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $this->tracedBilling();
        $this->tracedShipping();
        $orders->sendCommandWithRouting('order.invoice', ['orderId' => 1], metadata: [MessageHeaders::TRACEPARENT => (new SpanContext(self::INVOICED, 'aaaaaaaaaaaaaaaa'))->toTraceparent()]);
        $this->deliverEverywhere();
        $this->pulseHavingHeardFrom($client, $this->tracedServices);

        return $client;
    }
}
