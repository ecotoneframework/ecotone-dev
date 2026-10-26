<?php

declare(strict_types=1);

namespace App\Tests\Awaited;

use App\Tests\Support\ConcurrentRespondersTrait;
use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class AwaitedRequestsTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;
    use DeadLetterServicesTrait {
        TracedServicesTrait::pulseWebClient insteadof DeadLetterServicesTrait;
    }
    use ConcurrentRespondersTrait;

    public function test_a_page_view_renders_the_fresh_snapshot_in_the_same_response_when_the_service_answers(): void
    {
        $this->configurePulseWithServices('orders');
        $this->awaitRepliesFor(30);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders']);

        $crawler = $client->request('GET', '/services');

        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    public function test_a_service_that_does_not_answer_returns_after_the_timeout_and_falls_back_to_waiting(): void
    {
        $this->configurePulseWithServices('orders');
        $this->awaitRepliesFor(2);
        $client = $this->pulseClient();

        $startedAt = microtime(true);
        $crawler = $client->request('GET', '/services');
        $waited = microtime(true) - $startedAt;

        self::assertGreaterThanOrEqual(2.0, $waited);
        self::assertLessThan(8.0, $waited);
        self::assertSame('waiting', $this->statusOf($crawler, 'orders'));
        self::assertCount(1, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    public function test_the_wait_is_one_budget_for_every_service_not_one_per_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $this->awaitRepliesFor(2);
        $client = $this->pulseClient();

        $startedAt = microtime(true);
        $client->request('GET', '/services');

        self::assertLessThan(5.0, microtime(true) - $startedAt);
    }

    public function test_a_refresh_is_answered_by_a_service_that_tracks_its_own_flows(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $this->awaitRepliesFor(30);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders'], fn () => ['orders' => $this->tracedOrders()]);
        $this->respondInBackground(['billing', 'shipping']);

        $client->request('POST', '/refresh');
        $crawler = $client->followRedirect();

        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertCount(0, $crawler->filter('[data-service="orders"] [data-status="waiting"]'));
    }

    public function test_deleting_dead_letters_renders_the_snapshot_without_them_in_the_same_response(): void
    {
        $this->configurePulseWithServices('orders');
        $this->awaitRepliesFor(30);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders'], function (): array {
            $orders = $this->serviceWithDeadLetters('orders');
            $this->failOrder($orders, 'order.place', 1);
            $this->failOrder($orders, 'order.place', 2);

            return ['orders' => $orders];
        });

        $list = $client->request('GET', '/dead-letters');
        self::assertCount(2, $list->filter('[data-dead-letter]'));
        $confirmation = $client->request('POST', '/dead-letters/actions', ['action' => 'delete', 'scope' => 'ids', 'selected' => ['orders' => $list->filter('[data-dead-letter] input[name="selected[orders][]"]')->each(static fn (Crawler $box) => $box->attr('value'))]]);
        $client->submit($confirmation->filter('[data-confirm-delete] button')->form());
        $after = $client->followRedirect();

        self::assertCount(0, $after->filter('[data-dead-letter]'));
        self::assertCount(0, $after->filter('meta[http-equiv="refresh"]'));
    }

    public function test_deleting_a_failure_says_how_many_dead_letters_were_deleted_once_the_service_answers(): void
    {
        $this->configurePulseWithServices('orders');
        $this->awaitRepliesFor(30);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders'], function (): array {
            $orders = $this->serviceWithDeadLetters('orders');
            $this->failOrder($orders, 'order.place', 1);
            $this->failOrder($orders, 'order.place', 2);
            $this->failOrder($orders, 'order.cancel', 3);

            return ['orders' => $orders];
        });

        $list = $client->request('GET', '/dead-letters');
        $confirmation = $client->submit($list->filter('[data-group][data-message-name="order.place"] [data-group-delete]')->form());
        $client->submit($confirmation->filter('[data-confirm-delete] button')->form());
        $notice = $client->followRedirect();

        self::assertSame('2', $notice->filter('[data-notice] [data-affected]')->text());
        self::assertStringContainsString('deleted 2 dead letters', preg_replace('/\s+/', ' ', $notice->filter('[data-notice]')->text()));
        self::assertStringContainsString('RuntimeException', $notice->filter('[data-notice]')->text());
    }

    public function test_the_trace_list_of_a_flow_type_is_rendered_in_the_same_response_when_the_service_answers(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $this->awaitRepliesFor(30);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders'], function (): array {
            $orders = $this->tracedOrders();
            $orders->sendCommandWithRouting('order.place', ['orderId' => 1]);
            $orders->sendCommandWithRouting('order.place', ['orderId' => 2]);

            return ['orders' => $orders];
        });
        $this->respondInBackground(['billing', 'shipping']);

        $crawler = $client->request('GET', '/traces/orders/order.place');

        self::assertCount(2, $crawler->filter('[data-trace]'));
        self::assertCount(0, $crawler->filter('[data-waiting]'));
    }

    public function test_a_fan_out_renders_each_service_only_once_its_own_reply_was_processed(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $this->awaitRepliesFor(30);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders']);
        $this->respondInBackground(['billing'], startAfterSeconds: 6);

        $startedAt = microtime(true);
        $crawler = $client->request('GET', '/services');

        self::assertGreaterThanOrEqual(6.0, microtime(true) - $startedAt);
        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertSame('connected', $this->statusOf($crawler, 'billing'));
    }

    public function test_a_fan_out_renders_the_fast_service_fresh_and_the_slow_one_as_waiting_when_the_budget_ends(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $this->awaitRepliesFor(4);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders']);
        $this->respondInBackground(['billing'], startAfterSeconds: 30);

        $crawler = $client->request('GET', '/services');

        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertSame('waiting', $this->statusOf($crawler, 'billing'));
    }

    public function test_a_trace_search_renders_each_service_only_once_its_own_reply_was_processed(): void
    {
        $traceId = '55555555555555555555555555555555';
        $inTrace = [MessageHeaders::TRACEPARENT => (new SpanContext($traceId, 'aaaaaaaaaaaaaaaa'))->toTraceparent()];
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $this->awaitRepliesFor(40);
        $client = $this->pulseClient();
        $this->respondInBackground(['orders'], function () use ($inTrace): array {
            $orders = $this->tracedOrders();
            $orders->sendCommandWithRouting('order.place', ['orderId' => 1], metadata: $inTrace);

            return ['orders' => $orders];
        });
        $this->respondInBackground(['shipping']);
        $this->respondInBackground(['billing'], function () use ($inTrace): array {
            $billing = $this->tracedBilling();
            $billing->sendCommandWithRouting('invoice.create', 'payload', metadata: $inTrace);

            return ['billing' => $billing];
        }, startAfterSeconds: 6);

        $startedAt = microtime(true);
        $crawler = $client->request('GET', '/traces/trace/' . $traceId);

        self::assertGreaterThanOrEqual(6.0, microtime(true) - $startedAt);
        self::assertCount(1, $crawler->filter('[data-service-state="orders"][data-state="answered"]'));
        self::assertCount(1, $crawler->filter('[data-service-state="billing"][data-state="answered"]'));
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    private function pulseClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }

    private function statusOf(Crawler $crawler, string $service): string
    {
        return $crawler->filter(sprintf('[data-service="%s"] [data-status]', $service))->attr('data-status');
    }
}
