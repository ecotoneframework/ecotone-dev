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

    public function test_topology_diagram_connects_the_services_that_send_commands(): void
    {
        $diagram = $this->diagramOf('/topology', 'topology');

        self::assertStringStartsWith('graph LR', $diagram);
        self::assertMatchesRegularExpression('/^    t0 -->\|"[^"]+"\| t1$/m', $diagram);
        self::assertStringContainsString('["orders"]', $diagram);
        self::assertStringContainsString('["billing"]', $diagram);
    }

    private function diagramOf(string $uri, string $name): string
    {
        $client = $this->pulseWithServices();
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
