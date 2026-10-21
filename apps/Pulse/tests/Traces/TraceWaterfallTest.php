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
final class TraceWaterfallTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    private const PLACED = '11111111111111111111111111111111';
    private const INVOICED = '22222222222222222222222222222222';
    private const EXPLODED = '44444444444444444444444444444444';

    public function test_the_header_summarises_total_services_queue_wait_and_failed_steps(): void
    {
        $client = $this->pulseWithAllServices();

        $invoiced = $this->traceView($client, self::INVOICED);
        $exploded = $this->traceView($client, self::EXPLODED);

        self::assertSame('2', $invoiced->filter('[data-summary="services"] b')->text());
        self::assertSame('0', $invoiced->filter('[data-summary="failed"] b')->text());
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $invoiced->filter('[data-summary="total"] b')->text());
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $invoiced->filter('[data-summary="queueWait"] b')->text());
        self::assertStringContainsString($this->serviceChannels['billing'], $invoiced->filter('[data-summary="queueWait"]')->text());
        self::assertSame('1', $exploded->filter('[data-summary="failed"] b')->text());
    }

    public function test_every_step_has_a_bar_positioned_from_its_offset_and_duration_over_the_whole_trace(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $total = (int) $crawler->filter('[data-timeline-total]')->attr('data-timeline-total');
        self::assertGreaterThan(0, $total);
        $bars = $crawler->filter('[data-span] [data-bar]');
        self::assertCount($crawler->filter('[data-span]')->count(), $bars);
        $bars->each(function (Crawler $bar) use ($total): void {
            self::assertEqualsWithDelta((int) $bar->attr('data-offset') / $total * 100, $this->percentOf($bar, 'left'), 0.01);
            self::assertEqualsWithDelta(max(0.8, (int) $bar->attr('data-duration') / $total * 100), $this->percentOf($bar, 'width'), 0.01);
        });
        self::assertSame('0', $bars->first()->attr('data-offset'));
    }

    public function test_bars_carry_the_colour_of_their_service(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $colourOf = fn (string $service): string => $crawler->filter('[data-span][data-service="' . $service . '"] [data-bar]')->first()->attr('data-service-colour');
        self::assertNotSame($colourOf('orders'), $colourOf('billing'));
        self::assertSame($colourOf('orders'), $crawler->filter('[data-span][data-service="orders"] [data-bar]')->last()->attr('data-service-colour'));
    }

    public function test_the_wait_in_the_queue_is_drawn_as_a_hatched_segment_before_the_consumer_bar(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $received = $crawler->filter('[data-span][data-hop]');
        self::assertCount(1, $received->filter('[data-wait-bar]'));
        self::assertGreaterThan(0.0, $this->percentOf($received->filter('[data-wait-bar]'), 'width'));
        self::assertLessThanOrEqual($this->percentOf($received->filter('[data-bar]'), 'left') + 0.01, $this->percentOf($received->filter('[data-wait-bar]'), 'left') + $this->percentOf($received->filter('[data-wait-bar]'), 'width'));
        self::assertCount(0, $crawler->filter('[data-span][data-service="orders"]')->first()->filter('[data-wait-bar]'));
    }

    public function test_a_failed_step_is_announced_in_a_banner_with_links_to_its_dead_letter_to_replay_it_and_to_the_graph(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $banner = $crawler->filter('[data-failure-banner]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('orders', $banner->text());
        self::assertStringContainsString('RuntimeException', $banner->text());
        $deadLetter = $banner->filter('a[data-dead-letter-link]');
        self::assertCount(1, $deadLetter);
        self::assertSame($deadLetter->attr('href'), $banner->filter('a[data-replay-dead-letter]')->attr('href'));
        self::assertSame('/flow?trace=' . self::EXPLODED, $banner->filter('a[data-banner-graph]')->attr('href'));
        self::assertCount(0, $this->traceView($client, self::INVOICED)->filter('[data-failure-banner]'));
    }

    public function test_the_send_and_receive_steps_that_went_well_are_collapsed_per_service_but_failures_and_waiting_steps_never_are(): void
    {
        $client = $this->pulseWithAllServices();

        $invoiced = $this->traceView($client, self::INVOICED);
        $exploded = $this->traceView($client, self::EXPLODED);

        $collapsed = $invoiced->filter('[data-span][data-boilerplate]');
        self::assertGreaterThan(0, $collapsed->count());
        $collapsed->each(function (Crawler $row): void {
            self::assertMatchesRegularExpression('/^(send|receive) /', trim($row->filter('[data-column="name"]')->text()));
            self::assertNull($row->attr('data-failed'));
            self::assertNull($row->attr('data-pending'));
        });
        $summaries = $invoiced->filter('[data-collapsed-summary]');
        self::assertGreaterThan(0, $summaries->count());
        self::assertMatchesRegularExpression('/^orders · \d+ send\/receive steps?/', trim($summaries->first()->text()));
        self::assertCount(0, $exploded->filter('[data-span][data-failed][data-boilerplate]'));
    }

    public function test_a_step_sent_to_a_queue_and_not_consumed_yet_is_explained_as_pending_and_told_apart_from_stuck(): void
    {
        $client = $this->pulseWithAllServices();
        $this->tracedServices['orders']->sendCommandWithRouting('order.place', ['orderId' => 7], metadata: $this->inTrace('55555555555555555555555555555555'));
        $this->pulseHavingHeardFrom($client, $this->tracedServices);

        $pending = $this->traceView($client, '55555555555555555555555555555555');
        $completed = $this->traceView($client, self::PLACED);

        $explainer = $pending->filter('[data-pending-explainer]');
        self::assertCount(1, $explainer);
        self::assertStringContainsString('orders_async', $explainer->text());
        self::assertStringContainsString('not consumed yet', $explainer->text());
        self::assertStringContainsString('ecotone:run orders_async', $explainer->text());
        self::assertCount(0, $completed->filter('[data-pending-explainer]'));
    }

    public function test_the_pending_explainer_stays_when_another_step_of_the_trace_failed(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $this->tracedBilling();
        $this->tracedShipping();
        $both = '99999999999999999999999999999999';
        $orders->sendCommandWithRouting('order.explode', ['orderId' => 2], metadata: $this->inTrace($both));
        $this->consume('orders', 'orders_async');
        $orders->sendCommandWithRouting('order.place', ['orderId' => 2], metadata: $this->inTrace($both));
        $this->pulseHavingHeardFrom($client, $this->tracedServices);

        $crawler = $this->traceView($client, $both);

        self::assertCount(1, $crawler->filter('[data-trace-status="failed"]'));
        self::assertCount(1, $crawler->filter('[data-failure-banner]'));
        self::assertCount(1, $crawler->filter('[data-pending-explainer]'));
        self::assertStringContainsString('ecotone:run orders_async', $crawler->filter('[data-pending-explainer]')->text());
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
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1], metadata: $this->inTrace(self::PLACED));
        $orders->sendCommandWithRouting('order.invoice', ['orderId' => 1], metadata: $this->inTrace(self::INVOICED));
        $orders->sendCommandWithRouting('order.explode', ['orderId' => 1], metadata: $this->inTrace(self::EXPLODED));
        $this->consume('orders', 'orders_async');
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
