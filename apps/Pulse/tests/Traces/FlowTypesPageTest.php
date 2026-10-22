<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;

/**
 * @internal
 */
final class FlowTypesPageTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    public function test_traces_page_lists_the_flow_types_of_a_service_with_their_statistics(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $orders->sendCommandWithRouting('order.place', ['orderId' => 2]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-flow-type="order.place"][data-service="orders"]');
        self::assertCount(1, $row);
        self::assertSame('2', $row->filter('[data-stat="runs"]')->text());
        self::assertSame('0', $row->filter('[data-stat="runsWithErrors"]')->text());
        self::assertSame('0%', $row->filter('[data-stat="errorRate"]')->text());
        self::assertNotSame('', $row->filter('[data-stat="p50"]')->text());
        self::assertNotSame('', $row->filter('[data-stat="p95"]')->text());
        self::assertNotSame('', $row->filter('[data-stat="max"]')->text());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $row->filter('[data-stat="lastRun"]')->text());
    }

    public function test_traces_page_says_flow_tracking_is_not_enabled_for_a_service_without_it_and_shows_how_to_enable_it(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders(tracing: false);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces');

        $notice = $crawler->filter('[data-tracing-disabled="orders"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('flow tracking not enabled', $notice->text());
        self::assertStringContainsString('FlowTrackingConfiguration::createWithDefaults()', $notice->text());
        self::assertCount(0, $crawler->filter('[data-flow-type]'));
    }

    public function test_the_same_flow_type_in_two_services_is_listed_once_per_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $billing = $this->tracedBilling();
        $orders->sendCommandWithRouting('order.invoice', ['orderId' => 1]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);
        $billing->run($this->serviceChannels['billing'], \Ecotone\Api\ExtensionObject\ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseHavingHeardFrom($client, ['billing' => $billing]);

        $crawler = $client->request('GET', '/traces');

        self::assertCount(1, $crawler->filter('[data-flow-type="order.invoice"][data-service="orders"]'));
        self::assertCount(1, $crawler->filter('[data-flow-type="invoice.create"][data-service="billing"]'));
    }

    public function test_a_trace_id_with_a_slash_is_refused_with_a_message_instead_of_being_ignored(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();

        $crawler = $client->request('GET', '/traces', ['traceId' => 'a/b']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"a/b"', $crawler->filter('[data-search-error]')->text());
        self::assertStringContainsString('cannot contain a slash', $crawler->filter('[data-search-error]')->text());
    }
}
