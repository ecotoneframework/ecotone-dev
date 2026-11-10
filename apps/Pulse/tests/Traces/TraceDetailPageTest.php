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
final class TraceDetailPageTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    private const PLACED = '11111111111111111111111111111111';
    private const INVOICED = '22222222222222222222222222222222';
    private const ANNOUNCED = '33333333333333333333333333333333';
    private const EXPLODED = '44444444444444444444444444444444';

    public function test_searching_by_trace_id_opens_the_trace(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $client->request('GET', '/traces');
        self::assertCount(1, $crawler->filter('form[data-trace-search] input[name="traceId"]'));
        $client->request('GET', '/traces', ['traceId' => ' ' . self::PLACED . ' ']);

        self::assertResponseRedirects('/traces/trace/' . self::PLACED);
    }

    public function test_the_first_view_of_a_trace_waits_for_every_connected_service_and_names_who_is_pending(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $client->request('GET', '/traces/trace/' . self::INVOICED);

        self::assertResponseIsSuccessful();
        foreach (['orders', 'billing', 'shipping'] as $service) {
            self::assertCount(1, $crawler->filter('[data-service-state="' . $service . '"][data-state="waiting"]'));
        }
        self::assertCount(1, $crawler->filter('meta[http-equiv="refresh"]'));
        self::assertCount(0, $crawler->filter('[data-span]'));
    }

    public function test_spans_of_one_trace_from_two_services_are_merged_into_one_nested_waterfall(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        foreach (['orders', 'billing'] as $service) {
            self::assertCount(1, $crawler->filter('[data-service-state="' . $service . '"][data-state="answered"]'));
        }
        self::assertSame(
            ['orders|send command|0', 'orders|process class@anonymous::invoice|1', 'orders|send distributed|2', 'orders|send ' . $this->serviceChannels['billing'] . '|1', 'billing|receive ' . $this->serviceChannels['billing'] . '|2', 'billing|send command|3', 'billing|process class@anonymous::create|4'],
            $this->rows($crawler),
        );
        self::assertCount(1, $crawler->filter('[data-trace-status="completed"]'));
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $crawler->filter('[data-total-duration]')->text());
    }

    public function test_a_hop_to_another_service_is_marked_and_its_wait_in_the_queue_is_shown_apart_from_handler_time(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $hop = $crawler->filter('[data-span][data-service="billing"][data-hop]');
        self::assertCount(1, $hop);
        self::assertStringContainsString('receive ' . $this->serviceChannels['billing'], $hop->text());
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $hop->filter('[data-column="queueWait"]')->text());
        self::assertSame('', $crawler->filter('[data-span][data-service="orders"]')->first()->filter('[data-column="queueWait"]')->text());
        self::assertNotSame('', $hop->filter('[data-column="duration"]')->text());
    }

    public function test_every_row_shows_service_kind_start_offset_duration_and_status(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $process = $crawler->filter('[data-span][data-service="orders"]')->reduce(fn (Crawler $row) => str_contains($row->text(), 'process class@anonymous::invoice'))->first();
        self::assertSame('server', $process->filter('[data-column="kind"]')->text());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $process->filter('[data-column="startedAt"]')->text());
        self::assertMatchesRegularExpression('/^\+\d+(\.\d+)? ms$/', $process->filter('[data-column="offset"]')->text());
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $process->filter('[data-column="duration"]')->text());
        self::assertSame('ok', $process->filter('[data-column="status"]')->text());
        self::assertSame('order.invoice.handler', $process->filter('[data-column="endpoint"]')->text());
    }

    public function test_an_event_published_to_several_services_has_a_branch_in_each(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::ANNOUNCED);

        $hops = $crawler->filter('[data-span][data-hop]')->each(fn (Crawler $row) => $row->attr('data-service'));
        sort($hops);
        self::assertSame(['billing', 'shipping'], $hops);
        self::assertContains('billing|process class@anonymous::whenAnnounced|5', $this->rows($crawler));
        self::assertContains('shipping|process class@anonymous::whenAnnounced|5', $this->rows($crawler));
    }

    public function test_an_asynchronous_step_shows_its_queue_wait_and_is_completed_once_consumed(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::PLACED);

        $received = $crawler->filter('[data-span][data-service="orders"]')->reduce(fn (Crawler $row) => str_starts_with(trim($row->filter('[data-column="name"]')->text()), 'receive orders_async'));
        self::assertCount(1, $received);
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $received->filter('[data-column="queueWait"]')->text());
        self::assertCount(1, $crawler->filter('[data-trace-status="completed"]'));
        self::assertCount(0, $crawler->filter('[data-pending]'));
    }

    public function test_an_asynchronous_step_sent_but_not_processed_yet_is_pending(): void
    {
        $client = $this->pulseWithAllServices();
        $this->tracedServices['orders']->sendCommandWithRouting('order.place', ['orderId' => 7], metadata: $this->inTrace('55555555555555555555555555555555'));
        $this->heard($client);

        $crawler = $this->traceView($client, '55555555555555555555555555555555');

        self::assertCount(1, $crawler->filter('[data-trace-status="pending"]'));
        $pending = $crawler->filter('[data-span][data-pending]');
        self::assertCount(1, $pending);
        self::assertStringContainsString('send orders_async', $pending->text());
        self::assertStringContainsString('pending', $pending->filter('[data-column="status"]')->text());
    }

    public function test_a_failed_asynchronous_step_is_shown_failed_with_its_dead_letter_exception_and_a_link_to_the_dead_letter(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        self::assertCount(1, $crawler->filter('[data-trace-status="failed"]'));
        $failed = $crawler->filter('[data-span][data-failed]');
        self::assertCount(1, $failed);
        self::assertSame('process class@anonymous::explode', trim($failed->filter('[data-column="name"]')->text()));
        self::assertStringContainsString('RuntimeException', $failed->filter('[data-column="error"]')->text());
        self::assertStringContainsString('Order 1 exploded <script>alert(1)</script>', $failed->filter('[data-column="error"]')->text());
        self::assertStringNotContainsString('exploded <script>alert(1)', $client->getResponse()->getContent());
        self::assertStringContainsString('exploded &lt;script&gt;alert(1)&lt;/script&gt;', $client->getResponse()->getContent());
        $link = $failed->filter('a[data-dead-letter-link]');
        self::assertCount(1, $link);
        $detail = $client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSame('order.explode', $detail->filter('[data-detail="messageName"]')->text());
    }

    public function test_the_send_of_a_message_that_failed_in_its_handler_stays_sent_and_its_wait_in_the_queue_is_shown(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $send = $crawler->filter('[data-span]')->reduce(fn (Crawler $row) => trim($row->filter('[data-column="name"]')->text()) === 'send orders_async');
        self::assertCount(1, $send);
        self::assertNull($send->attr('data-failed'));
        $received = $crawler->filter('[data-span]')->reduce(fn (Crawler $row) => trim($row->filter('[data-column="name"]')->text()) === 'receive orders_async');
        self::assertSame('failed', $received->filter('[data-column="status"]')->text());
        self::assertNotNull($received->attr('data-failed-within'));
        self::assertCount(0, $received->filter('a[data-dead-letter-link]'));
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $received->filter('[data-column="queueWait"]')->text());
    }

    public function test_the_handling_tile_of_a_failed_trace_names_the_failing_handler(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $tile = $crawler->filter('[data-summary="ownTime"]');
        self::assertStringContainsString('in the failing handler class@anonymous::explode', $tile->text());
        self::assertSame($crawler->filter('[data-flame-span][data-failed]')->attr('href'), $tile->filter('a')->attr('href'));
    }

    public function test_the_services_tile_counts_the_services_in_the_trace_apart_from_the_services_asked(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $tile = $crawler->filter('[data-summary="services"]');
        self::assertSame('1', $tile->filter('b')->text());
        self::assertStringContainsString('Services in this trace', $tile->text());
        self::assertStringContainsString('of 3 asked', $tile->text());
    }

    public function test_dead_letters_with_a_trace_offer_a_link_to_the_trace(): void
    {
        $client = $this->pulseWithAllServices();

        $list = $client->request('GET', '/dead-letters');

        $links = $list->filter('[data-dead-letter][data-service="orders"] a[data-view-trace]');
        self::assertCount(1, $links);
        self::assertSame('/traces/trace/' . self::EXPLODED, $links->attr('href'));
        $detail = $client->request('GET', '/dead-letters/orders/' . $list->filter('[data-dead-letter][data-service="orders"]')->attr('data-message-id'));
        self::assertSame('/traces/trace/' . self::EXPLODED, $detail->filter('a[data-view-trace]')->attr('href'));
    }

    public function test_dead_letters_of_a_service_without_flow_tracking_offer_no_link_to_a_trace(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders(tracing: false);
        $orders->sendCommandWithRouting('order.explode', ['orderId' => 1]);
        $this->consume('orders', 'orders_async');
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $list = $client->request('GET', '/dead-letters');

        self::assertCount(1, $list->filter('[data-dead-letter][data-service="orders"]'));
        self::assertCount(0, $list->filter('a[data-view-trace]'));
        $detail = $client->request('GET', '/dead-letters/orders/' . $list->filter('[data-dead-letter]')->attr('data-message-id'));
        self::assertCount(0, $detail->filter('a[data-view-trace]'));
    }

    public function test_a_trace_nobody_stored_says_so(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, 'ffffffffffffffffffffffffffffffff');

        self::assertCount(1, $crawler->filter('[data-no-spans]'));
    }

    public function test_a_service_that_does_not_answer_the_trace_query_is_shown_as_not_answering(): void
    {
        $client = $this->pulseWithAllServices();
        $client->request('GET', '/traces/trace/' . self::INVOICED);
        $this->advanceTimeBy(11);

        $crawler = $client->request('GET', '/traces/trace/' . self::INVOICED);

        self::assertCount(3, $crawler->filter('[data-state="not_answering"]'));
    }

    public function test_services_without_flow_tracking_are_not_asked(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders(tracing: false);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces/trace/' . self::INVOICED);

        self::assertCount(1, $crawler->filter('[data-service-state="orders"][data-state="tracing_disabled"]'));
    }

    public function test_a_failed_trace_opens_with_a_verdict_naming_the_service_the_failing_step_and_the_exception(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $verdict = $crawler->filter('main > [data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('warning', $verdict->attr('data-verdict'));
        self::assertStringStartsWith('Failed in orders at handler class@anonymous::explode after it was taken from orders_async', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertStringContainsString('RuntimeException', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertStringContainsString('Order 1 exploded', $verdict->filter('[data-failure-banner]')->text());
        self::assertSame(['orders', 'orders_async', 'class@anonymous::explode', 'order.explode.handler'], array_slice($verdict->filter('[data-problem-where] li')->each(static fn (Crawler $place): string => trim($place->text())), 0, 4));
        self::assertSame($crawler->filter('[data-flame-span][data-failed]')->attr('href'), $verdict->filter('a[data-failing-step]')->attr('href'));
        self::assertCount(1, $verdict->filter('a.pri[data-dead-letter-link]'));
        self::assertCount(1, $verdict->filter('a[data-replay-dead-letter]'));
        self::assertSame('/flow?trace=' . self::EXPLODED, $verdict->filter('a[data-banner-graph]')->attr('href'));
        self::assertCount(0, $crawler->filter('main > p > a[href="/traces"]'));
    }

    public function test_a_completed_trace_says_how_long_it_took_across_how_many_services(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $verdict = $crawler->filter('main > [data-verdict]');
        self::assertSame('ok', $verdict->attr('data-verdict'));
        self::assertMatchesRegularExpression('/^Completed in \d+(\.\d+)? ms across 2 services; no step failed\.$/', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertCount(0, $crawler->filter('[data-failure-banner]'));
    }

    public function test_a_trace_still_waiting_in_a_queue_is_reported_as_running_not_failed(): void
    {
        $client = $this->pulseWithAllServices();
        $this->tracedServices['orders']->sendCommandWithRouting('order.place', ['orderId' => 7], metadata: $this->inTrace('55555555555555555555555555555555'));
        $this->heard($client);

        $crawler = $this->traceView($client, '55555555555555555555555555555555');

        $verdict = $crawler->filter('main > [data-verdict]');
        self::assertSame('info', $verdict->attr('data-verdict'));
        self::assertStringContainsString('orders_async', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertCount(1, $crawler->filter('[data-pending-explainer]'));
    }

    public function test_the_breadcrumbs_lead_back_through_the_root_service_and_its_flow_type(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $crumbs = $crawler->filter('nav[aria-label="Breadcrumb"] li');
        self::assertSame(['Home', 'Services', 'orders', 'Traces', 'order.invoice', 'Trace 22222222…'], $crumbs->each(static fn (Crawler $crumb): string => trim($crumb->text())));
        self::assertSame(['/', '/services', '/services/orders', '/traces?service=orders', '/traces/orders/order.invoice'], $crumbs->filter('a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function test_a_trace_nobody_stored_has_breadcrumbs_to_the_traces_page_only(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, 'ffffffffffffffffffffffffffffffff');

        $crumbs = $crawler->filter('nav[aria-label="Breadcrumb"] li');
        self::assertSame(['Home', 'Traces', 'Trace ffffffff…'], $crumbs->each(static fn (Crawler $crumb): string => trim($crumb->text())));
        self::assertSame('unknown', $crawler->filter('main > [data-verdict]')->attr('data-verdict'));
    }

    public function test_the_key_facts_say_where_the_time_went_and_link_to_the_step(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        $hop = $crawler->filter('[data-flame-span][data-hop]');
        self::assertSame($hop->attr('href'), $crawler->filter('[data-summary="queueWait"] a')->attr('href'));
        $ownTime = $crawler->filter('[data-summary="ownTime"]');
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $ownTime->filter('b')->text());
        self::assertStringStartsWith('#span-', (string) $ownTime->filter('a')->attr('href'));
        self::assertCount(1, $crawler->filter($ownTime->filter('a')->attr('href') . '[data-span-panel]'));
        self::assertStringContainsString('7 steps', $crawler->filter('[data-summary="services"]')->text());
    }

    public function test_the_answers_of_the_services_collapse_into_one_line_once_every_asked_service_answered(): void
    {
        $client = $this->pulseWithAllServices();

        $waiting = $client->request('GET', '/traces/trace/' . self::INVOICED);
        self::assertNotNull($waiting->filter('details[data-section="trace.answers"]')->attr('open'));
        self::assertStringContainsString('Pulse asked 3 services and 0 answered', $waiting->filter('details[data-section="trace.answers"] summary')->text());

        $answered = $this->traceView($client, self::INVOICED);

        $answers = $answered->filter('details[data-section="trace.answers"]');
        self::assertNull($answers->attr('open'));
        self::assertStringContainsString('Pulse asked 3 services and all answered', $answers->filter('summary')->text());
        self::assertCount(3, $answers->filter('[data-service-state][data-state="answered"]'));
    }

    public function test_a_step_shows_at_most_eight_key_facts_and_keeps_its_attributes_and_events_behind_a_disclosure(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $crawler->filter('[data-span-panel]')->each(function (Crawler $panel): void {
            self::assertLessThanOrEqual(8, $panel->filter('dl[data-panel-facts] > dt')->count());
            self::assertCount(0, $panel->filter('dl[data-panel-facts] [data-panel-attributes]'));
        });
        $panel = $crawler->filter($crawler->filter('[data-flame-span][data-failed]')->attr('href'));
        self::assertCount(1, $panel->filter('dl[data-panel-facts] [data-panel="error"]'));
        self::assertCount(1, $panel->filter('details[data-panel-more] [data-panel-attributes]'));
        self::assertNull($panel->filter('details[data-panel-more]')->attr('open'));
    }

    public function test_the_step_list_is_collapsed_with_its_count_of_steps_and_failures_and_nothing_is_nested_deeper(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::EXPLODED);

        $list = $crawler->filter('details[data-list-view]');
        self::assertCount(1, $list);
        self::assertNull($list->attr('open'));
        self::assertMatchesRegularExpression('/\d+ steps · 1 failed/', $list->filter('summary')->text());
        self::assertCount(1, $list->filter('table.waterfall'));
        self::assertCount(0, $crawler->filter('details details'));
    }

    public function test_the_concepts_a_beginner_meets_on_a_trace_are_explained_where_they_first_appear(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        foreach (['trace', 'span', 'queue-wait', 'flow-type', 'hop'] as $term) {
            self::assertGreaterThan(0, $crawler->filter('[data-concept="' . $term . '"]')->count(), $term);
        }
    }

    public function test_the_trace_opens_in_the_tracing_ui_when_pulse_trace_url_is_set(): void
    {
        $client = $this->pulseWithAllServices('http://localhost:16686/trace/{traceId}');

        $crawler = $this->traceView($client, self::INVOICED);

        $link = $crawler->filter('a[data-external-trace]');
        self::assertCount(1, $link);
        self::assertSame('http://localhost:16686/trace/' . self::INVOICED, $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener', $link->attr('rel'));
        self::assertCount(1, $crawler->filter('a[data-show-on-flow-graph]'));
    }

    public function test_without_pulse_trace_url_the_trace_offers_no_tracing_ui_link(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        self::assertCount(0, $crawler->filter('a[data-external-trace]'));
    }

    private function pulseWithAllServices(?string $traceUrl = null): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        if ($traceUrl !== null) {
            $this->exportPulseVariable('PULSE_TRACE_URL', $traceUrl);
        }
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $this->tracedBilling();
        $this->tracedShipping();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1], metadata: $this->inTrace(self::PLACED));
        $orders->sendCommandWithRouting('order.invoice', ['orderId' => 1], metadata: $this->inTrace(self::INVOICED));
        $orders->sendCommandWithRouting('order.announce', ['orderId' => 1], metadata: $this->inTrace(self::ANNOUNCED));
        $orders->sendCommandWithRouting('order.explode', ['orderId' => 1], metadata: $this->inTrace(self::EXPLODED));
        $this->consume('orders', 'orders_async');
        $this->deliverEverywhere();
        $this->heard($client);

        return $client;
    }

    private function heard(KernelBrowser $client): void
    {
        $this->pulseHavingHeardFrom($client, $this->tracedServices);
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

    /**
     * @return list<string>
     */
    private function rows(Crawler $crawler): array
    {
        return $crawler->filter('[data-span]')->each(fn (Crawler $row) => $row->attr('data-service') . '|' . trim($row->filter('[data-column="name"]')->text()) . '|' . $row->attr('data-depth'));
    }
}
