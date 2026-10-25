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
final class DatabaseStoredTraceTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    private const INVOICED = '99999999999999999999999999999991';
    private const ANNOUNCED = '99999999999999999999999999999992';

    protected function setUp(): void
    {
        parent::setUp();
        $this->databasePerService = true;
    }

    protected function tearDown(): void
    {
        $this->dropServiceDatabases();
        parent::tearDown();
    }

    public function test_a_distributed_command_is_nested_across_services_when_each_service_stores_its_traces_in_its_own_database(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::INVOICED);

        self::assertSame(
            ['orders|send command|0', 'orders|process class@anonymous::invoice|1', 'orders|send distributed|2', 'orders|send ' . $this->serviceChannels['billing'] . '|1', 'billing|receive ' . $this->serviceChannels['billing'] . '|2', 'billing|send command|3', 'billing|process class@anonymous::create|4'],
            $this->rows($crawler),
        );
        $hop = $crawler->filter('[data-span][data-service="billing"][data-hop]');
        self::assertCount(1, $hop);
        self::assertMatchesRegularExpression('/^\d+(\.\d+)? ms$/', $hop->filter('[data-column="queueWait"]')->text());
        self::assertCount(1, $crawler->filter('[data-trace-status="completed"]'));
    }

    public function test_an_event_fanned_out_to_two_services_has_a_branch_in_each_when_traces_are_stored_in_databases(): void
    {
        $client = $this->pulseWithAllServices();

        $crawler = $this->traceView($client, self::ANNOUNCED);

        $hops = $crawler->filter('[data-span][data-hop]')->each(fn (Crawler $row) => $row->attr('data-service'));
        sort($hops);
        self::assertSame(['billing', 'shipping'], $hops);
        self::assertContains('billing|process class@anonymous::whenAnnounced|5', $this->rows($crawler));
        self::assertContains('shipping|process class@anonymous::whenAnnounced|5', $this->rows($crawler));
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

    /**
     * @return list<string>
     */
    private function rows(Crawler $crawler): array
    {
        return $crawler->filter('[data-span]')->each(fn (Crawler $row) => $row->attr('data-service') . '|' . trim($row->filter('[data-column="name"]')->text()) . '|' . $row->attr('data-depth'));
    }
}
