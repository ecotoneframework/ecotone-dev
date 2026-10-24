<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TraceRefusalPageTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private const TRACE = '0af7651916cd43dd8448eb211c80319c';

    private object $orders;
    private array $services = [];

    public function test_a_trace_list_the_service_refused_shows_its_message_where_the_list_would_be(): void
    {
        $client = $this->pulseWithOrders('The `perPage` of a Pulse trace list query is `26`, but it must be a number from 1 to 25.');

        $page = $this->answered($client, '/traces/orders/order.place');

        self::assertStringContainsString('orders refused this query: The `perPage` of a Pulse trace list query is `26`', $page->filter('[data-refused="orders"]')->text());
        self::assertCount(0, $page->filter('[data-waiting]'));
        self::assertCount(0, $page->filter('[data-not-answering]'));
    }

    public function test_a_trace_the_service_refused_to_load_is_shown_in_the_state_of_that_service(): void
    {
        $client = $this->pulseWithOrders('Service orders was asked by Pulse for traces, but flow tracking is not configured there.');

        $page = $this->answered($client, '/traces/trace/' . self::TRACE);

        self::assertSame('refused', $page->filter('[data-service-state="orders"]')->attr('data-state'));
        self::assertStringContainsString('refused this query: Service orders was asked by Pulse for traces, but flow tracking is not configured there.', $page->filter('[data-service-state="orders"]')->text());
    }

    public function test_a_refused_snapshot_shows_on_the_home_board_the_services_list_and_the_service_page(): void
    {
        $client = $this->pulseWithOrders('unused');
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->orders->snapshotRefusal = 'The snapshot of tenant `tenant_c` cannot be taken.';
        $this->advanceTimeBy(60);
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());

        $home = $client->request('GET', '/');
        $services = $client->request('GET', '/services');
        $hub = $client->request('GET', '/services/orders');

        self::assertStringContainsString('Refused the snapshot request: The snapshot of tenant `tenant_c` cannot be taken.', $home->filter('[data-problem="snapshot-refused"]')->text());
        self::assertStringContainsString('The snapshot of tenant `tenant_c` cannot be taken.', $services->filter('[data-snapshot-refusal]')->text());
        self::assertStringContainsString('orders refused the snapshot request: The snapshot of tenant `tenant_c` cannot be taken.', $hub->filter('[data-snapshot-refusal]')->text());
        self::assertCount(0, $hub->filter('[data-request-error]'));
    }

    public function test_a_good_snapshot_ends_the_refusal(): void
    {
        $client = $this->pulseWithOrders('unused');
        $this->orders->snapshotRefusal = 'refused';
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->orders->snapshotRefusal = null;
        $this->advanceTimeBy(60);
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());

        $hub = $client->request('GET', '/services/orders');

        self::assertCount(0, $hub->filter('[data-snapshot-refusal]'));
    }

    private function answered(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $client->request('GET', $uri);
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }

    private function pulseWithOrders(string $traceRefusal): KernelBrowser
    {
        $suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->serviceChannels = ['orders' => 'orders_' . $suffix];
        $this->configurePulse(['PULSE_CHANNEL' => $this->pulseChannel, 'PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders']]);
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->orders = new class ($traceRefusal) {
            public ?string $snapshotRefusal = null;

            public function __construct(private string $traceRefusal)
            {
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot', endpointId: 'orders.snapshot')]
            public function snapshot(string $request): string
            {
                if ($this->snapshotRefusal !== null) {
                    return $this->refusal('ecotone.pulse.snapshot', $this->snapshotRefusal, null);
                }

                return json_encode([
                    'schemaVersion' => 1,
                    'serviceName' => 'orders',
                    'takenAt' => gmdate('c'),
                    'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
                    'flowGraph' => ['nodes' => [], 'edges' => []],
                    'topology' => ['serviceMaps' => []],
                    'channels' => [],
                    'tracing' => ['storage' => 'dbal', 'statsWindow' => 100, 'flowTypes' => [['flowType' => 'order.place', 'runs' => 1, 'runsWithErrors' => 0, 'p50Ms' => 1, 'p95Ms' => 1, 'maxMs' => 1]]],
                ], JSON_THROW_ON_ERROR);
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.traces.list', endpointId: 'orders.traces.list')]
            public function list(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                return $this->refusal('ecotone.pulse.traces.list', $this->traceRefusal, $queryId);
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.traces.trace', endpointId: 'orders.traces.trace')]
            public function trace(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                return $this->refusal('ecotone.pulse.traces.trace', $this->traceRefusal, $queryId);
            }

            private function refusal(string $requestKind, string $message, ?string $queryId): string
            {
                return json_encode(['requestKind' => $requestKind, 'serviceName' => 'orders', 'tenant' => null, 'message' => $message, 'queryId' => $queryId, 'tracingId' => null, 'failedAt' => gmdate('c')], JSON_THROW_ON_ERROR);
            }
        };
        $this->services['orders'] = $this->serviceApplication('orders', [$this->orders::class], [$this->orders], false);

        return $client;
    }
}
