<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\HighlightedDiagram;
use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TraceOnFlowGraphTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    private const INVOICED = '22222222222222222222222222222222';
    private const ANNOUNCED = '33333333333333333333333333333333';
    private const EXPLODED = '44444444444444444444444444444444';
    private const NOTIFYING = '55555555555555555555555555555555';
    private const SYNCED_BY_COMMAND = '77777777777777777777777777777777';
    private const SYNCED_BY_EVENT = '88888888888888888888888888888888';

    public function test_the_trace_page_links_to_the_flow_graph_highlighting_the_trace(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/traces/trace/' . self::INVOICED);

        self::assertSame('/flow?trace=' . self::INVOICED, $crawler->filter('a[data-show-on-flow-graph]')->attr('href'));
    }

    public function test_the_flow_graph_highlights_the_nodes_the_trace_passed_and_leaves_the_others(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::INVOICED));

        self::assertTrue($graph->hasNode('tracePassed', 'orders', '::invoice'));
        self::assertTrue($graph->hasNode('tracePassed', 'billing', 'invoice.create'));
        self::assertFalse($graph->hasNode('tracePassed', 'orders', '::announce'));
        self::assertFalse($graph->hasNode('tracePassed', 'billing', 'invoice.create.later'));
        self::assertSame([], $graph->nodesOf('traceFailed'));
        self::assertSame([], $graph->nodesOf('tracePending'));
    }

    public function test_the_distributed_command_edge_between_the_services_is_highlighted(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::INVOICED));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
        self::assertCount(count(array_unique($graph->highlightedEdges())), $graph->highlightedEdges());
    }

    public function test_an_event_published_to_several_services_highlights_the_edge_to_each(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?view=detail&trace=' . self::ANNOUNCED));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'order.announced', 'distributed event', 'billing', 'order.announced'));
        self::assertTrue($graph->isEdgeHighlighted('orders', 'order.announced', 'distributed event', 'shipping', 'order.announced'));
        self::assertFalse($graph->isEdgeHighlighted('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
    }

    public function test_only_the_command_edge_is_highlighted_when_the_trace_took_the_command(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::SYNCED_BY_COMMAND));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed command', 'billing', 'invoice.sync'));
        self::assertFalse($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed event', 'billing', 'invoice.synced'));
    }

    public function test_only_the_event_edge_is_highlighted_when_the_trace_took_the_event(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::SYNCED_BY_EVENT));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed event', 'billing', 'invoice.synced'));
        self::assertFalse($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed command', 'billing', 'invoice.sync'));
    }

    public function test_a_failed_asynchronous_step_is_marked_failed_on_the_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::EXPLODED));

        self::assertTrue($graph->hasNode('traceFailed', 'orders', '::explode'));
        self::assertFalse($graph->hasNode('tracePassed', 'orders', '::explode'));
    }

    public function test_a_step_sent_but_not_processed_yet_is_marked_pending_on_the_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::NOTIFYING));

        self::assertTrue($graph->hasNode('tracePending', 'orders', '::notify'));
        self::assertTrue($graph->hasNode('tracePassed', 'orders', '::place'));
        self::assertSame([], $graph->nodesOf('traceFailed'));
    }

    public function test_the_service_overview_of_a_trace_highlights_the_services_it_crossed_and_the_distributed_edges_it_took(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/flow?view=overview&trace=' . self::INVOICED);

        self::assertCount(1, $crawler->filter('[data-flow-overview]'));
        self::assertCount(1, $crawler->filter('[data-trace-banner]'));
        $overview = $this->overviewOf($crawler);
        self::assertSame(['billing', 'orders'], $this->highlightedServices($overview, 'tracePassed'));
        self::assertTrue($this->highlightedEdge($overview, 'orders', 'billing', 'command'));
        self::assertFalse($this->highlightedEdge($overview, 'orders', 'shipping', 'event'));
    }

    public function test_a_trace_crossing_more_nodes_than_the_limit_opens_as_the_highlighted_overview_and_the_detail_stays_one_click_away(): void
    {
        $client = $this->pulseWithAllServices();

        $overview = $this->traceView($client, '/flow?trace=' . self::ANNOUNCED);
        $detail = $this->traceView($client, '/flow?view=detail&trace=' . self::ANNOUNCED);

        self::assertCount(1, $overview->filter('[data-flow-overview]'));
        self::assertSame(['billing', 'orders', 'shipping'], $this->highlightedServices($this->overviewOf($overview), 'tracePassed'));
        self::assertCount(0, $detail->filter('[data-flow-overview]'));
        self::assertStringContainsString('subgraph', $detail->filter('pre.mermaid')->text());
    }

    public function test_a_trace_within_the_node_limit_still_opens_as_the_detailed_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/flow?trace=' . self::INVOICED);

        self::assertCount(0, $crawler->filter('[data-flow-overview]'));
        self::assertStringContainsString('subgraph', $crawler->filter('pre.mermaid')->text());
    }

    /**
     * @return array{services: array<string, string>, lines: list<string>}
     */
    private function overviewOf(Crawler $crawler): array
    {
        $lines = explode("\n", rtrim($crawler->filter('pre.mermaid')->getNode(0)->textContent));
        $services = [];
        foreach ($lines as $line) {
            if (preg_match('/^    (s\d+)\["([^"]*)"\]$/', $line, $match) === 1) {
                $services[$match[1]] = $match[2];
            }
        }

        return ['services' => $services, 'lines' => $lines];
    }

    /**
     * @param array{services: array<string, string>, lines: list<string>} $overview
     * @return list<string>
     */
    private function highlightedServices(array $overview, string $state): array
    {
        $names = [];
        foreach ($overview['lines'] as $line) {
            if (preg_match('/^    class ([\w,]+) ' . $state . '$/', $line, $match) === 1) {
                foreach (explode(',', $match[1]) as $id) {
                    $names[] = $overview['services'][$id];
                }
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @param array{services: array<string, string>, lines: list<string>} $overview
     */
    private function highlightedEdge(array $overview, string $from, string $to, string $kind): bool
    {
        $edges = [];
        $highlighted = [];
        foreach ($overview['lines'] as $line) {
            if (preg_match('/^    (s\d+) (?:==>|-\.->)\|"\d+ (\w+?)s?"\| (s\d+)$/', $line, $match) === 1) {
                $edges[] = [$overview['services'][$match[1]], $overview['services'][$match[3]], $match[2]];
            } elseif (preg_match('/^    linkStyle ([\d,]+) /', $line, $match) === 1) {
                $highlighted = array_map('intval', explode(',', $match[1]));
            }
        }
        foreach ($edges as $index => $edge) {
            if ($edge === [$from, $to, $kind]) {
                return in_array($index, $highlighted, true);
            }
        }

        return false;
    }

    public function test_the_highlight_carries_no_fixed_colours_so_the_design_tokens_colour_it_in_both_schemes(): void
    {
        $client = $this->pulseWithAllServices();

        $source = (new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::EXPLODED)))->diagram->source;

        self::assertStringContainsString('classDef traceFailed stroke-width:3px', $source);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}|var\(|fill:|stroke:/i', $source);
    }

    public function test_highlighted_nodes_carry_the_duration_of_the_step(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = new HighlightedDiagram($this->traceView($client, '/flow?trace=' . self::INVOICED));

        $labels = array_filter($graph->nodesOf('tracePassed'), fn (string $node) => str_contains($node, '::invoice'));
        self::assertMatchesRegularExpression('/<br\/>\d+(\.\d+)? ms$/', (string) array_values($labels)[0]);
    }

    public function test_the_graph_only_shows_the_services_the_trace_crossed_unless_others_are_asked_for(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/flow?trace=' . self::INVOICED);

        self::assertCount(2, $crawler->filter('input[name="services[]"]:checked'));
        self::assertCount(1, $crawler->filter('[data-trace-banner]'));
        self::assertStringContainsString(self::INVOICED, $crawler->filter('[data-trace-banner]')->text());
    }

    public function test_the_flow_graph_without_a_trace_has_no_highlight(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $client->request('GET', '/flow');

        self::assertCount(0, $crawler->filter('[data-trace-banner]'));
        self::assertStringNotContainsString('tracePassed', $crawler->filter('pre.mermaid')->text());
    }

    private function pulseWithAllServices(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $this->tracedBilling();
        $this->tracedShipping();
        $orders->sendCommandWithRouting('order.invoice', ['orderId' => 1], metadata: $this->inTrace(self::INVOICED));
        $orders->sendCommandWithRouting('order.announce', ['orderId' => 1], metadata: $this->inTrace(self::ANNOUNCED));
        $orders->sendCommandWithRouting('order.explode', ['orderId' => 1], metadata: $this->inTrace(self::EXPLODED));
        $this->consume('orders', 'orders_async');
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1], metadata: $this->inTrace(self::NOTIFYING));
        $orders->sendCommandWithRouting('order.sync', ['orderId' => 1], metadata: $this->inTrace(self::SYNCED_BY_COMMAND));
        $orders->sendCommandWithRouting('order.sync_event', ['orderId' => 1], metadata: $this->inTrace(self::SYNCED_BY_EVENT));
        $this->deliverEverywhere();
        $this->pulseHavingHeardFrom($client, $this->tracedServices);

        return $client;
    }

    /**
     * @return array<string, string>
     */
    private function inTrace(string $traceId): array
    {
        return [MessageHeaders::TRACEPARENT => (new SpanContext($traceId, 'aaaaaaaaaaaaaaaa'))->toTraceparent()];
    }

    private function traceView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('GET', $uri);
        foreach ($this->tracedServices as $name => $service) {
            $this->runService($service, $name);
        }
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }
}
