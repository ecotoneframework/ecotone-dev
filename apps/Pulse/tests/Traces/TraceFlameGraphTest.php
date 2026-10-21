<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TraceFlameGraphTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    private const INVOICED = '22222222222222222222222222222222';
    private const ANNOUNCED = '33333333333333333333333333333333';
    private const EXPLODED = '44444444444444444444444444444444';
    private const PENDING = '55555555555555555555555555555555';

    public function test_every_step_is_a_bar_placed_by_its_offset_and_duration_on_a_shared_time_axis(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $total = (int) $crawler->filter('[data-flame]')->attr('data-timeline-total');
        self::assertGreaterThan(0, $total);
        $bars = $crawler->filter('[data-flame-span]');
        self::assertCount($crawler->filter('[data-span]')->count(), $bars);
        $bars->each(function (Crawler $bar) use ($total): void {
            self::assertEqualsWithDelta((int) $bar->attr('data-offset') / $total * 100, $this->percentOf($bar, 'left'), 0.01);
            self::assertEqualsWithDelta(max(0.5, (int) $bar->attr('data-duration') / $total * 100), $this->percentOf($bar, 'width'), 0.01);
        });
        self::assertSame('0', $bars->first()->attr('data-offset'));
        self::assertGreaterThanOrEqual(3, $crawler->filter('[data-flame-tick]')->count());
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? (ms|s)$/', $crawler->filter('[data-flame-tick]')->last()->text());
    }

    public function test_a_step_is_drawn_on_a_lane_below_its_parent_even_when_the_parent_is_in_another_service(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $lane = [];
        $crawler->filter('[data-flame-span]')->each(function (Crawler $bar) use (&$lane): void {
            $lane[$bar->attr('data-service') . '|' . $bar->attr('data-name')] = (int) $bar->attr('data-lane');
        });
        $received = 'billing|receive ' . $this->serviceChannels['billing'];
        $sent = 'orders|send ' . $this->serviceChannels['billing'];
        self::assertArrayHasKey($received, $lane);
        self::assertGreaterThan($lane[$sent], $lane[$received]);
        self::assertGreaterThan($lane[$received], $lane['billing|send command']);
        self::assertSame(0, $lane['orders|send command']);
    }

    public function test_an_event_fanned_out_to_two_services_has_a_branch_in_each_coloured_apart(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::ANNOUNCED);

        $handlers = $crawler->filter('[data-flame-span][data-name$="whenAnnounced"]');
        $services = $handlers->each(fn (Crawler $bar) => $bar->attr('data-service'));
        sort($services);
        self::assertSame(['billing', 'shipping'], $services);
        self::assertNotSame($handlers->eq(0)->attr('data-service-colour'), $handlers->eq(1)->attr('data-service-colour'));
        $legend = $crawler->filter('[data-flame-legend] [data-legend-service]')->each(fn (Crawler $item) => $item->attr('data-legend-service'));
        self::assertEqualsCanonicalizing(['orders', 'billing', 'shipping'], $legend);
    }

    public function test_the_wait_in_the_queue_before_a_hop_is_drawn_ending_where_the_consumer_bar_starts(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $hop = $crawler->filter('[data-flame-span][data-hop]');
        self::assertCount(1, $hop);
        $wait = $crawler->filter('[data-flame-wait][data-for="' . $hop->attr('data-index') . '"]');
        self::assertCount(1, $wait);
        self::assertSame($hop->attr('data-lane'), $wait->attr('data-lane'));
        self::assertEqualsWithDelta($this->percentOf($hop, 'left'), $this->percentOf($wait, 'left') + $this->percentOf($wait, 'width'), 0.01);
    }

    public function test_failed_and_pending_steps_are_flagged_on_their_bars(): void
    {
        $client = $this->pulseWithAllServices();

        $failed = $this->traceView($client, self::EXPLODED);
        $pending = $this->traceView($client, self::PENDING);

        self::assertCount(1, $failed->filter('[data-flame-span][data-failed]'));
        self::assertCount(0, $failed->filter('[data-flame-span][data-pending]'));
        self::assertCount(1, $pending->filter('[data-flame-span][data-pending]'));
    }

    public function test_selecting_a_step_opens_a_server_rendered_panel_with_everything_known_about_it(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $process = $crawler->filter('[data-flame-span][data-service="orders"][data-name$="invoice"]')->first();
        self::assertStringStartsWith('#span-', $process->attr('href'));
        $panel = $crawler->filter($process->attr('href'));
        self::assertCount(1, $panel);
        self::assertSame('orders', $panel->filter('[data-panel="service"]')->text());
        self::assertStringContainsString('process class@anonymous::invoice', $panel->filter('[data-panel="name"]')->text());
        self::assertSame('server', $panel->filter('[data-panel="kind"]')->text());
        self::assertSame('order.invoice.handler', $panel->filter('[data-panel="endpoint"]')->text());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $panel->filter('[data-panel="startedAt"]')->text());
        self::assertMatchesRegularExpression('/^\+\d+(\.\d+)? ms$/', $panel->filter('[data-panel="offset"]')->text());
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $panel->filter('[data-panel="duration"]')->text());
        self::assertSame('ok', $panel->filter('[data-panel="status"]')->text());
        self::assertGreaterThan(0, $panel->filter('[data-panel-attributes] tr')->count());
        self::assertStringContainsString('ecotone.endpoint.id', $panel->filter('[data-panel-attributes]')->text());
        self::assertSame('/traces', $panel->filter('a[data-panel-service-traces]')->attr('href'));
        self::assertStringStartsWith('/flow?trace=' . self::INVOICED, $panel->filter('a[data-panel-flow-graph]')->attr('href'));
    }

    public function test_the_panel_of_a_failed_step_shows_its_error_and_links_to_the_dead_letter(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $panel = $crawler->filter($crawler->filter('[data-flame-span][data-failed]')->attr('href'));
        self::assertStringContainsString('RuntimeException', $panel->filter('[data-panel="error"]')->text());
        self::assertSame('failed', $panel->filter('[data-panel="status"]')->text());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $panel->filter('[data-panel="messageId"]')->text());
        $detail = $client->click($panel->filter('a[data-panel-dead-letter]')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('order.explode', $detail->filter('[data-detail="messageName"]')->text());
    }

    public function test_span_data_is_escaped_in_bars_tooltips_and_panels(): void
    {
        $client = $this->pulseWithAllServices();

        $this->traceView($client, self::EXPLODED);

        $html = $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('exploded &lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_flame_graph_works_without_javascript_and_keeps_the_table_as_the_list_view(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $bar = $crawler->filter('[data-flame-span]')->first();
        self::assertSame('a', $bar->nodeName());
        self::assertNotSame('', trim($bar->attr('title') ?? ''));
        self::assertNotSame('', trim($bar->attr('aria-label') ?? ''));
        self::assertCount($crawler->filter('[data-flame-span]')->count(), $crawler->filter('[data-span-panel]'));
        self::assertCount(1, $crawler->filter('[data-list-view] table.waterfall'));
        self::assertCount(1, $crawler->filter('[data-flame-controls] button[data-zoom="in"]'));
        self::assertCount(1, $crawler->filter('[data-flame-controls] button[data-zoom="reset"]'));
    }

    private function percentOf(Crawler $bar, string $property): float
    {
        self::assertSame(1, preg_match('/' . $property . ':\s*([\d.]+)%/', (string) $bar->attr('style'), $match));

        return (float) $match[1];
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
        $this->deliverEverywhere();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 7], metadata: $this->inTrace(self::PENDING));
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

    private function traceView(KernelBrowser $client, string $traceId): Crawler
    {
        $client->request('GET', '/traces/trace/' . $traceId);
        foreach ($this->tracedServices as $name => $service) {
            $this->runService($service, $name);
        }
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', '/traces/trace/' . $traceId);
    }
}
