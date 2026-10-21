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
        self::assertStringContainsString('send orders_async', $failed->text());
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

    private function pulseWithAllServices(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
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
