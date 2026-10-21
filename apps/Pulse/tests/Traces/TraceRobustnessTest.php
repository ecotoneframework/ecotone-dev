<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TraceRobustnessTest extends WebTestCase
{
    use TracedServicesTrait;

    private const TRACE = '66666666666666666666666666666666';

    public function test_markup_in_span_names_attributes_events_and_failures_is_escaped(): void
    {
        $hostile = '<img src=x onerror=alert(1)>';
        $span = [
            'spanId' => 'aaaaaaaaaaaaaaa1', 'parentSpanId' => null, 'name' => $hostile, 'kind' => 'server', 'status' => 'error', 'statusDescription' => $hostile,
            'startedAt' => '2026-10-20T12:00:00.000000+00:00', 'endedAt' => '2026-10-20T12:00:00.100000+00:00',
            'attributes' => ['ecotone.endpoint.id' => $hostile, 'custom' => $hostile],
            'events' => [['name' => $hostile, 'at' => '2026-10-20T12:00:00.000000+00:00', 'attributes' => ['exception.message' => $hostile, 'exception.type' => $hostile]]],
        ];
        $client = $this->pulseWithFakeService([$span], truncated: false);

        $crawler = $this->traceView($client);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('img'));
        self::assertStringNotContainsString('<img', $client->getResponse()->getContent());
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $client->getResponse()->getContent());
        self::assertSame(1, $crawler->filter('[data-span]')->count());
    }

    public function test_a_large_trace_shows_the_first_steps_and_says_how_many_were_left_out(): void
    {
        $spans = [['spanId' => 'bbbbbbbbbbbbbbb0', 'parentSpanId' => null, 'name' => 'root', 'kind' => 'server', 'status' => 'ok', 'statusDescription' => null, 'startedAt' => '2026-10-20T12:00:00.000000+00:00', 'endedAt' => '2026-10-20T12:00:01.000000+00:00', 'attributes' => [], 'events' => []]];
        for ($step = 1; $step <= 349; $step++) {
            $spans[] = ['spanId' => sprintf('bbbbbbbbbbbbb%03d', $step), 'parentSpanId' => 'bbbbbbbbbbbbbbb0', 'name' => 'step ' . $step, 'kind' => 'internal', 'status' => 'ok', 'statusDescription' => null, 'startedAt' => sprintf('2026-10-20T12:00:00.%06d+00:00', $step), 'endedAt' => sprintf('2026-10-20T12:00:00.%06d+00:00', $step + 1), 'attributes' => [], 'events' => []];
        }
        $client = $this->pulseWithFakeService($spans, truncated: false);

        $crawler = $this->traceView($client);

        self::assertCount(300, $crawler->filter('[data-span]'));
        self::assertStringContainsString('first 300 of 350', $crawler->filter('[data-rows-capped]')->text());
    }

    public function test_a_service_that_cut_its_reply_says_so(): void
    {
        $span = ['spanId' => 'ccccccccccccccc1', 'parentSpanId' => null, 'name' => 'root', 'kind' => 'server', 'status' => 'ok', 'statusDescription' => null, 'startedAt' => '2026-10-20T12:00:00.000000+00:00', 'endedAt' => '2026-10-20T12:00:00.100000+00:00', 'attributes' => [], 'events' => []];
        $client = $this->pulseWithFakeService([$span], truncated: true, totalSpans: 5000);

        $crawler = $this->traceView($client);

        self::assertStringContainsString('5000', $crawler->filter('[data-reply-truncated]')->text());
    }

    public function test_traces_stored_in_the_database_are_listed_and_shown(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $traceId = bin2hex(random_bytes(16));
        $orders = $this->tracedService('orders', $this->ordersHandler(), \Ecotone\Api\ExtensionObject\DistributedServiceMap::initialize(), inMemory: false);
        $orders->sendCommandWithRouting('order.persisted', ['orderId' => 1], metadata: [MessageHeaders::TRACEPARENT => (new SpanContext($traceId, 'aaaaaaaaaaaaaaaa'))->toTraceparent()]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $overview = $client->request('GET', '/traces');
        self::assertGreaterThanOrEqual(1, (int) $overview->filter('[data-flow-type="order.persisted"] [data-stat="runs"]')->text());
        $client->request('GET', '/traces/orders/order.persisted');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $list = $client->request('GET', '/traces/orders/order.persisted');
        self::assertContains($traceId, $list->filter('[data-trace]')->each(fn (Crawler $trace) => $trace->attr('data-trace-id')));
        $detail = $this->traceView($client, $orders, $traceId);

        self::assertCount(2, $detail->filter('[data-span][data-service="orders"]'));
        self::assertCount(1, $detail->filter('[data-trace-status="completed"]'));
    }

    private function ordersHandler(): object
    {
        return new class () {
            #[CommandHandler('order.persisted', endpointId: 'order.persisted.handler')]
            public function persisted(array $order): void
            {
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $spans
     */
    private function pulseWithFakeService(array $spans, bool $truncated, ?int $totalSpans = null): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $snapshot = json_encode(['schemaVersion' => 1, 'serviceName' => 'orders', 'overview' => [], 'tracing' => ['storage' => 'dbal', 'retention' => 604800, 'statsWindow' => 1000, 'flowTypes' => []]], JSON_THROW_ON_ERROR);
        $trace = json_encode(['queryId' => 'QUERY_ID', 'traceId' => self::TRACE, 'totalSpans' => $totalSpans ?? count($spans), 'truncated' => $truncated, 'spans' => $spans], JSON_THROW_ON_ERROR);
        $handler = new class ($snapshot, $trace) {
            public function __construct(private string $snapshot, private string $trace)
            {
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot')]
            public function snapshot(string $request): string
            {
                return $this->snapshot;
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.traces.trace')]
            public function trace(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                return str_replace('QUERY_ID', $queryId, $this->trace);
            }
        };
        $this->fake = $this->serviceApplication('orders', [$handler::class], [$handler], false);
        $this->pulseHavingHeardFrom($client, ['orders' => $this->fake]);

        return $client;
    }

    private ?FlowTestSupport $fake = null;

    private function traceView(KernelBrowser $client, ?FlowTestSupport $service = null, string $traceId = self::TRACE): Crawler
    {
        $client->request('GET', '/traces/trace/' . $traceId);
        $this->runService($service ?? $this->fake, 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', '/traces/trace/' . $traceId);
    }
}
