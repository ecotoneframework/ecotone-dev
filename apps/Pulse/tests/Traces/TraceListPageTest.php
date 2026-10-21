<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Lite\Test\FlowTestSupport;
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
            usleep(2000);
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
