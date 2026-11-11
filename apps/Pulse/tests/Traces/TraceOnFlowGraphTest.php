<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\Diagram;
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

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::INVOICED));

        self::assertTrue($graph->hasNodeInState('tracePassed', 'orders', '::invoice'));
        self::assertTrue($graph->hasNodeInState('tracePassed', 'billing', 'invoice.create'));
        self::assertFalse($graph->hasNodeInState('tracePassed', 'orders', '::announce'));
        self::assertFalse($graph->hasNodeInState('tracePassed', 'billing', 'invoice.create.later'));
        self::assertSame([], $graph->nodesInState('traceFailed'));
        self::assertSame([], $graph->nodesInState('tracePending'));
    }

    public function test_the_steps_of_the_trace_are_listed_under_the_graph_numbered_like_the_nodes_they_passed(): void
    {
        $client = $this->pulseWithAllServices();

        $page = $this->traceView($client, '/flow?trace=' . self::INVOICED);
        $graph = Diagram::fromPage($page);

        $numbered = array_values(array_unique($page->filter('[data-trace-drawer] tr[data-trace-step]')->each(static fn (Crawler $row): string => $row->attr('data-trace-step'))));
        self::assertNotSame([], $numbered);
        $onGraph = array_map('strval', array_values(array_filter(array_column($graph->nodes, 'step'))));
        self::assertSame([], array_diff($numbered, $onGraph));
        self::assertSame('/traces/trace/' . self::INVOICED, $page->filter('[data-trace-drawer] a[data-trace-drawer-full]')->attr('href'));
    }

    public function test_the_failed_step_of_a_trace_is_marked_failed_in_the_list_under_the_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $page = $this->traceView($client, '/flow?trace=' . self::EXPLODED);

        self::assertGreaterThan(0, $page->filter('[data-trace-drawer] tr[data-trace-step-status="failed"]')->count());
    }

    public function test_the_flow_graph_without_a_trace_lists_no_steps(): void
    {
        $client = $this->pulseWithAllServices();

        self::assertCount(0, $client->request('GET', '/flow')->filter('[data-trace-drawer]'));
    }

    public function test_the_distributed_command_edge_between_the_services_is_highlighted(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::INVOICED));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
        $highlighted = array_map(fn (array $edge) => implode('|', [$edge['from'], $edge['arrow'], $edge['label'], $edge['to']]), array_filter($graph->edges, fn (array $edge) => $edge['highlighted']));
        self::assertCount(count(array_unique($highlighted)), $highlighted);
    }

    public function test_an_event_published_to_several_services_highlights_the_edge_to_each(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?view=detail&trace=' . self::ANNOUNCED));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'order.announced', 'distributed event', 'billing', 'order.announced'));
        self::assertTrue($graph->isEdgeHighlighted('orders', 'order.announced', 'distributed event', 'shipping', 'order.announced'));
        self::assertFalse($graph->isEdgeHighlighted('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
    }

    public function test_only_the_command_edge_is_highlighted_when_the_trace_took_the_command(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::SYNCED_BY_COMMAND));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed command', 'billing', 'invoice.sync'));
        self::assertFalse($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed event', 'billing', 'invoice.synced'));
    }

    public function test_only_the_event_edge_is_highlighted_when_the_trace_took_the_event(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::SYNCED_BY_EVENT));

        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed event', 'billing', 'invoice.synced'));
        self::assertFalse($graph->isEdgeHighlighted('orders', 'invoice.sync', 'distributed command', 'billing', 'invoice.sync'));
    }

    public function test_a_failed_asynchronous_step_is_marked_failed_on_the_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::EXPLODED));

        self::assertTrue($graph->hasNodeInState('traceFailed', 'orders', '::explode'));
        self::assertFalse($graph->hasNodeInState('tracePassed', 'orders', '::explode'));
    }

    public function test_a_highlighted_failed_trace_is_the_verdict_and_names_the_failed_step_with_a_link_that_keeps_the_trace(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/flow?trace=' . self::EXPLODED);

        $verdict = $crawler->filter('[data-trace-banner] [data-verdict]');
        self::assertSame('warning', $verdict->attr('data-verdict'));
        self::assertMatchesRegularExpression('/^Trace 44444444… failed: \d+ nodes? failed and \d+ passed; the red nodes are where it failed\.$/', $verdict->filter('[data-verdict-sentence]')->text());
        $problem = $crawler->filter('[data-problem="trace-failed"]');
        self::assertStringContainsString('::explode failed in orders in this trace.', $problem->filter('[data-problem-text]')->text());
        $follow = (string) $problem->filter('[data-problem-action]')->attr('href');
        self::assertStringStartsWith('/flow?focus=orders', $follow);
        self::assertStringContainsString('trace=' . self::EXPLODED, $follow);
        self::assertSame('/traces/trace/' . self::EXPLODED, $problem->filter('[data-problem-secondary]')->attr('href'));
        self::assertNotNull($crawler->filter('details[data-graph-legend]')->attr('open'));
    }

    public function test_a_highlighted_trace_that_passed_is_an_ok_verdict_saying_where_it_went(): void
    {
        $client = $this->pulseWithAllServices();

        $verdict = $this->traceView($client, '/flow?trace=' . self::INVOICED)->filter('[data-trace-banner] [data-verdict]');

        self::assertSame('ok', $verdict->attr('data-verdict'));
        self::assertMatchesRegularExpression('/^Trace 22222222… passed through \d+ nodes in (orders and billing|billing and orders); green arrows show the way it went\.$/', $verdict->filter('[data-verdict-sentence]')->text());
    }

    public function test_a_step_sent_but_not_processed_yet_is_marked_pending_on_the_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::NOTIFYING));

        self::assertTrue($graph->hasNodeInState('tracePending', 'orders', '::notify'));
        self::assertTrue($graph->hasNodeInState('tracePassed', 'orders', '::place'));
        self::assertSame([], $graph->nodesInState('traceFailed'));
    }

    public function test_the_service_overview_of_a_trace_highlights_the_services_it_crossed_and_the_distributed_edges_it_took(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/flow?view=overview&trace=' . self::INVOICED);

        self::assertCount(1, $crawler->filter('[data-flow-overview]'));
        self::assertCount(1, $crawler->filter('[data-trace-banner]'));
        $overview = Diagram::fromPage($crawler);
        self::assertSame([], $overview->services);
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
        self::assertSame(['billing', 'orders', 'shipping'], $this->highlightedServices(Diagram::fromPage($overview), 'tracePassed'));
        self::assertCount(0, $detail->filter('[data-flow-overview]'));
        self::assertNotSame([], Diagram::fromPage($detail)->services);
    }

    public function test_a_trace_within_the_node_limit_still_opens_as_the_detailed_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/flow?trace=' . self::INVOICED);

        self::assertCount(0, $crawler->filter('[data-flow-overview]'));
        self::assertNotSame([], Diagram::fromPage($crawler)->services);
    }

    public function test_a_collapsed_service_takes_the_trace_state_of_the_service_and_the_arrow_the_trace_crossed_stays_highlighted(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::INVOICED . '&collapse[]=billing'));

        self::assertTrue($graph->hasNodeInState('tracePassed', 'billing', 'collapsed'));
        self::assertTrue($graph->hasNodeInState('tracePassed', 'orders', '::invoice'));
        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.create', 'distributed command', 'billing', 'collapsed'));
    }

    public function test_following_a_node_of_a_highlighted_trace_keeps_the_highlight_on_its_flow(): void
    {
        $client = $this->pulseWithAllServices();
        $page = $this->traceView($client, '/flow?trace=' . self::INVOICED);
        $invoice = array_values(array_filter(Diagram::fromPage($page)->nodes, fn (array $node) => $node['service'] === 'billing' && $node['title'] === 'invoice.create'));

        $focused = $this->traceView($client, (string) $invoice[0]['href']);

        self::assertCount(1, $focused->filter('[data-focus]'));
        $graph = Diagram::fromPage($focused);
        self::assertTrue($graph->hasNodeInState('tracePassed', 'orders', '::invoice'));
        self::assertTrue($graph->isEdgeHighlighted('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
        foreach ($focused->filter('[data-focus-upstream] a, [data-focus-downstream] a') as $link) {
            self::assertStringContainsString('trace=' . self::INVOICED, (string) $link->getAttribute('href'));
        }
    }

    /**
     * @return list<string>
     */
    private function highlightedServices(Diagram $overview, string $state): array
    {
        $names = array_values(array_column(array_filter($overview->nodes, fn (array $node) => $node['state'] === $state), 'name'));
        sort($names);

        return $names;
    }

    private function highlightedEdge(Diagram $overview, string $from, string $to, string $kind): bool
    {
        foreach ($overview->edges as $edge) {
            if ($overview->nodes[$edge['from']]['name'] === $from && $overview->nodes[$edge['to']]['name'] === $to && preg_match('/^\d+ ' . $kind . 's?$/', $edge['label']) === 1) {
                return $edge['highlighted'];
            }
        }

        return false;
    }

    public function test_the_highlight_carries_no_fixed_colours_so_the_design_tokens_colour_it_in_both_schemes(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, '/flow?trace=' . self::EXPLODED);

        self::assertNotSame([], Diagram::fromPage($crawler)->nodesInState('traceFailed'));
        self::assertCount(0, $crawler->filter('[data-diagram] [style], [data-diagram][style]'));
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{6}|var\(|fill:|stroke:/i', $crawler->filter('[data-diagram]')->outerHtml());
    }

    public function test_highlighted_nodes_carry_the_duration_of_the_step(): void
    {
        $client = $this->pulseWithAllServices();

        $graph = Diagram::fromPage($this->traceView($client, '/flow?trace=' . self::INVOICED));

        $labels = array_filter($graph->nodesInState('tracePassed'), fn (string $node) => str_contains($node, '::invoice'));
        self::assertMatchesRegularExpression('/ \d+(\.\d+)? ms$/', (string) array_values($labels)[0]);
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
        self::assertCount(0, $crawler->filter('[data-diagram][data-traced], [data-diagram] [data-trace-state], [data-diagram] [data-highlighted]'));
        self::assertStringNotContainsString('tracePassed', $crawler->filter('[data-diagram]')->outerHtml());
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
