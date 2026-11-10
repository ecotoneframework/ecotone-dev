<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\SpanContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TraceListPageTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    public function test_the_first_view_waits_for_the_service_and_refreshes_itself(): void
    {
        [$client, $orders] = $this->pulseWithOrders(2);

        $crawler = $client->request('GET', '/traces/orders/order.place');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-waiting="orders"]'));
        self::assertCount(1, $crawler->filter('meta[http-equiv="refresh"]'));
        self::assertCount(0, $crawler->filter('[data-trace]'));
    }

    public function test_the_traces_of_a_flow_type_are_listed_newest_first_with_id_start_duration_and_status(): void
    {
        [$client, $orders] = $this->pulseWithOrders(3);

        $crawler = $this->answered($client, $orders, '/traces/orders/order.place');

        self::assertCount(3, $crawler->filter('[data-trace]'));
        $first = $crawler->filter('[data-trace]')->first();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->attr('data-trace-id'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $first->filter('[data-column="startedAt"]')->text());
        self::assertMatchesRegularExpression('/^\d+ ms$/', $first->filter('[data-column="duration"]')->text());
        self::assertSame('ok', $first->filter('[data-column="status"]')->text());
        $started = $crawler->filter('[data-trace] [data-column="startedAt"]')->each(fn (Crawler $cell) => $cell->text());
        $sorted = $started;
        rsort($sorted);
        self::assertSame($sorted, $started);
    }

    public function test_two_runs_within_one_trace_are_listed_as_two_rows_told_apart_by_the_span_each_starts_at(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $traceparent = (new SpanContext('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331'))->toTraceparent();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1], metadata: [MessageHeaders::TRACEPARENT => $traceparent]);
        $orders->advanceTimeBy(Duration::milliseconds(2));
        $orders->sendCommandWithRouting('order.place', ['orderId' => 2], metadata: [MessageHeaders::TRACEPARENT => $traceparent]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);
        $this->advanceTimeBy(0);

        $rows = $this->answered($client, $orders, '/traces/orders/order.place')->filter('[data-trace]');

        self::assertSame(['0af7651916cd43dd8448eb211c80319c', '0af7651916cd43dd8448eb211c80319c'], $rows->each(fn (Crawler $row) => $row->attr('data-trace-id')));
        $entrySpanIds = $rows->each(fn (Crawler $row) => $row->attr('data-entry-span-id'));
        self::assertCount(2, array_unique($entrySpanIds));
        self::assertNotContains(null, $entrySpanIds);
    }

    public function test_traces_are_paged_by_twenty_five_with_controls_to_move_between_pages(): void
    {
        [$client, $orders] = $this->pulseWithOrders(30);

        $crawler = $this->answered($client, $orders, '/traces/orders/order.place');
        self::assertCount(25, $crawler->filter('[data-trace]'));
        self::assertSame('Page 1 of 2', $crawler->filter('[data-pagination]')->text());
        self::assertCount(0, $crawler->filter('[data-page-previous]'));
        $next = $crawler->filter('[data-page-next]')->attr('href');

        $second = $this->answered($client, $orders, $next);

        self::assertStringContainsString('page=2', $next);
        self::assertCount(5, $second->filter('[data-trace]'));
        self::assertSame('Page 2 of 2', $second->filter('[data-pagination]')->text());
        self::assertCount(0, $second->filter('[data-page-next]'));
        self::assertCount(1, $second->filter('[data-page-previous]'));
    }

    public function test_a_trace_list_is_cached_for_a_minute_and_asked_again_afterwards(): void
    {
        [$client, $orders] = $this->pulseWithOrders(1);
        $this->answered($client, $orders, '/traces/orders/order.place');
        $orders->sendCommandWithRouting('order.place', ['orderId' => 99]);

        $withinMinute = $this->answered($client, $orders, '/traces/orders/order.place');
        $this->advanceTimeBy(61);
        $afterMinute = $this->answered($client, $orders, '/traces/orders/order.place');

        self::assertCount(1, $withinMinute->filter('[data-trace]'));
        self::assertCount(2, $afterMinute->filter('[data-trace]'));
    }

    public function test_a_service_that_does_not_reply_in_time_is_shown_as_not_answering_for_this_query(): void
    {
        [$client, $orders] = $this->pulseWithOrders(1);
        $client->request('GET', '/traces/orders/order.place');
        $this->advanceTimeBy(11);

        $crawler = $client->request('GET', '/traces/orders/order.place');

        self::assertCount(1, $crawler->filter('[data-not-answering="orders"]'));
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    public function test_a_service_without_flow_tracking_has_no_trace_list(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders(tracing: false);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces/orders/order.place');

        self::assertCount(1, $crawler->filter('[data-tracing-disabled="orders"]'));
    }

    public function test_the_trace_list_leads_with_a_verdict_written_from_the_statistics_of_its_flow_type(): void
    {
        [$client, $orders] = $this->pulseWithOrders(3);

        $crawler = $this->answered($client, $orders, '/traces/orders/order.place');

        $verdict = $crawler->filter('[data-verdict]');
        self::assertSame('ok', $verdict->attr('data-verdict'));
        self::assertMatchesRegularExpression('/^3 runs, none failed; 95% finished within \d+ ms\.$/', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertMatchesRegularExpression('/^typical \d+ ms \(p50\) · \d+ ms \(p95\) .* · slowest \d+ ms · 0 of 3 with errors recorded \(0%\)$/', $verdict->filter('[data-statistics]')->text());
        self::assertCount(1, $verdict->filter('[data-statistics] [data-concept="p95"]'));
        self::assertCount(1, $crawler->filter('main > [data-verdict]'));
        self::assertCount(0, $crawler->filterXPath('//main/*[following-sibling::*[@data-verdict]][self::section or self::table or self::details]'));
    }

    public function test_a_flow_type_with_failed_runs_is_a_warning(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        [$orders] = $this->countingService('orders', json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'orders',
            'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'tracing' => ['statsWindow' => 1000, 'flowTypes' => [['flowType' => 'order.place', 'runs' => 4, 'windowRuns' => 4, 'runsWithErrors' => 1, 'p50Ms' => 5, 'p95Ms' => 9, 'maxMs' => 12]]],
        ], JSON_THROW_ON_ERROR));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces/orders/order.place');

        self::assertSame('warning', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('4 runs, 1 with errors recorded (25%); 95% finished within 9 ms.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertStringEndsWith('· slowest 12 ms · 1 of 4 with errors recorded (25%)', $crawler->filter('[data-statistics]')->text());
    }

    public function test_the_trace_list_has_breadcrumbs_back_to_the_service_and_its_traces(): void
    {
        [$client, $orders] = $this->pulseWithOrders(1);

        $crawler = $this->answered($client, $orders, '/traces/orders/order.place');

        $crumbs = $crawler->filter('nav[aria-label="Breadcrumb"] li');
        self::assertSame(['Home', 'Services', 'orders', 'Traces', 'order.place'], $crumbs->each(static fn (Crawler $crumb): string => trim($crumb->text())));
        self::assertSame(['/', '/services', '/services/orders', '/traces?service=orders'], $crumbs->filter('a')->each(static fn (Crawler $link): string => $link->attr('href')));
        self::assertSame('page', $crumbs->last()->filter('span')->attr('aria-current'));
    }

    public function test_the_trace_list_explains_what_a_flow_type_is_from_the_shared_glossary(): void
    {
        [$client, $orders] = $this->pulseWithOrders(1);

        $crawler = $this->answered($client, $orders, '/traces/orders/order.place');

        $concept = $crawler->filter('[data-concept="flow-type"]');
        self::assertCount(1, $concept);
        self::assertSame('What is a flow type?', $concept->filter('button.concept-toggle')->attr('aria-label'));
        self::assertSame('What it is: Traces grouped by how the flow entered the service: the name of the first message, such as order.place. Each service a flow reaches names it by the message that arrived there, so one business flow has a flow type in every service it crosses.', $concept->filter('[data-concept-what]')->text());
    }

    public function test_a_service_without_flow_tracking_says_why_and_how_to_enable_it(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders(tracing: false);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces/orders/order.place');

        self::assertSame('unknown', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('flow tracking is not enabled in orders', $crawler->filter('[data-verdict] [data-status-reason]')->text());
        $empty = $crawler->filter('[data-empty="not-enabled"][data-tracing-disabled="orders"]');
        self::assertStringContainsString('FlowTrackingConfiguration::createWithDefaults()', $empty->filter('[data-empty-snippet] code')->text());
    }

    public function test_an_unknown_service_is_not_found(): void
    {
        [$client] = $this->pulseWithOrders(1);

        $client->request('GET', '/traces/nobody/order.place');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_flow_type_with_slashes_and_markup_is_escaped(): void
    {
        [$client] = $this->pulseWithOrders(1);

        $crawler = $client->request('GET', '/traces/orders/' . rawurlencode('<img src=x onerror=alert(1)>'));

        self::assertCount(0, $crawler->filter('img'));
    }

    /**
     * @return array{KernelBrowser, FlowTestSupport}
     */
    private function pulseWithOrders(int $runs): array
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        for ($order = 1; $order <= $runs; $order++) {
            $orders->sendCommandWithRouting('order.place', ['orderId' => $order]);
            $orders->advanceTimeBy(Duration::milliseconds(2));
        }
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);
        $this->advanceTimeBy(0);

        return [$client, $orders];
    }

    private function answered(KernelBrowser $client, FlowTestSupport $service, string $uri): Crawler
    {
        $client->request('GET', $uri);
        $this->runService($service, 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }
}
