<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class CatalogueEvidenceLinksTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    public function test_a_message_that_starts_a_flow_links_to_its_traces(): void
    {
        $crawler = $this->catalogueOfTracedOrders();

        $traces = $crawler->filter('[data-service="orders"][data-message="order.place"] a[data-message-traces]');
        self::assertSame('/traces/orders/order.place', $traces->attr('href'));
        self::assertCount(0, $crawler->filter('[data-service="orders"][data-message="order.invoice"] a[data-message-traces]'));
    }

    public function test_a_message_with_dead_letters_links_to_them_with_their_count(): void
    {
        $crawler = $this->catalogueOfTracedOrders();

        $deadLetters = $crawler->filter('[data-service="orders"][data-message="order.explode"] a[data-message-dead-letters]');
        self::assertSame('/dead-letters?service=orders&messageName=order.explode', $deadLetters->attr('href'));
        self::assertSame('1 dead letter', $deadLetters->text());
        self::assertCount(0, $crawler->filter('[data-service="orders"][data-message="order.place"] a[data-message-dead-letters]'));
    }

    private function catalogueOfTracedOrders(): Crawler
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $orders->sendCommandWithRouting('order.explode', ['orderId' => 2]);
        $this->consume('orders', 'orders_async');
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        return $client->request('GET', '/catalogue?service=orders');
    }
}
