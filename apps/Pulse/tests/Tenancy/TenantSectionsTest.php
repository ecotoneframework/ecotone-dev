<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Headers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TenantSectionsTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private object $orders;

    /**
     * @var array<string, \Ecotone\Api\Lite\Test\FlowTestSupport>
     */
    private array $services = [];

    public function test_the_projections_page_lists_the_projections_of_each_tenant_in_its_own_section(): void
    {
        $client = $this->pulseWithMultiTenantOrders();
        $this->orders->projections = ['tenant_a' => [$this->projection('tickets', events: 12)], 'tenant_b' => [$this->projection('tickets', events: 0)]];

        $crawler = $this->observe($client, '/services/orders/projections');

        self::assertSame('catching_up', $this->statusOf($crawler, 'orders@tenant_a', 'tickets'));
        self::assertSame('up_to_date', $this->statusOf($crawler, 'orders@tenant_b', 'tickets'));
        self::assertSame('12 events behind', $crawler->filter('[data-projections-section="orders@tenant_a"] [data-gap]')->text());
    }

    public function test_a_projection_stalls_per_tenant_when_only_its_own_position_stops_moving(): void
    {
        $client = $this->pulseWithMultiTenantOrders();
        $this->orders->projections = ['tenant_a' => [$this->projection('tickets', events: 5, digest: 'same')], 'tenant_b' => [$this->projection('tickets', events: 5, digest: 'first')]];
        $this->observe($client, '/services/orders/projections');

        $this->advanceTimeBy(150);
        $this->orders->projections['tenant_b'] = [$this->projection('tickets', events: 5, digest: 'second')];
        $this->observe($client, '/services/orders/projections');
        $this->advanceTimeBy(150);
        $crawler = $this->observe($client, '/services/orders/projections');

        self::assertSame('stalled', $this->statusOf($crawler, 'orders@tenant_a', 'tickets'));
        self::assertSame('catching_up', $this->statusOf($crawler, 'orders@tenant_b', 'tickets'));
    }

    public function test_the_hub_measures_the_channels_on_the_tenant_connection_per_tenant(): void
    {
        $client = $this->pulseWithMultiTenantOrders();
        $this->orders->depths = ['tenant_a' => 5, 'tenant_b' => 0];

        $crawler = $this->observe($client, '/services/orders');

        self::assertSame('5', $crawler->filter('[data-hub-tenants] tr[data-channel="tenant_queue"][data-tenant="tenant_a"] [data-ready]')->text());
        self::assertSame('0', $crawler->filter('[data-hub-tenants] tr[data-channel="tenant_queue"][data-tenant="tenant_b"] [data-ready]')->text());
        self::assertCount(0, $crawler->filter('[data-hub-channels] tr[data-channel="tenant_queue"] [data-probe-error]'));
    }

    public function test_the_same_failure_in_two_tenants_is_listed_per_tenant_with_its_own_count(): void
    {
        $client = $this->pulseWithMultiTenantOrders();
        $this->orders->failures = ['tenant_a' => 2, 'tenant_b' => 1];

        $crawler = $this->observe($client, '/dead-letters');

        self::assertSame('2', $crawler->filter('[data-service-section="orders@tenant_a"] [data-service-total]')->text());
        self::assertSame('1', $crawler->filter('[data-service-section="orders@tenant_b"] [data-service-total]')->text());
        self::assertSame('same-failure', $crawler->filter('[data-service-section="orders@tenant_b"] [data-group]')->attr('data-failure'));
    }

    private function pulseWithMultiTenantOrders(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->orders = new class () {
            public array $projections = [];
            public array $depths = [];
            public array $failures = [];

            #[Distributed]
            #[CommandHandler('ecotone.pulse.trackings.list', endpointId: 'orders.trackings')]
            public function trackings(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                return json_encode(['queryId' => $queryId, 'cap' => 100, 'truncated' => false, 'trackings' => []], JSON_THROW_ON_ERROR);
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot')]
            public function snapshot(string $request, #[Headers] array $headers): string
            {
                $base = ['schemaVersion' => 1, 'serviceName' => 'orders', 'takenAt' => gmdate('c')];
                $tenant = $headers['tenant'] ?? null;
                if ($tenant === null) {
                    return json_encode($base + [
                        'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
                        'flowGraph' => ['nodes' => [], 'edges' => []],
                        'topology' => ['serviceMaps' => []],
                        'channels' => [['name' => 'tenant_queue', 'kind' => 'async', 'ready' => null, 'delayed' => null, 'inFlight' => null, 'consumers' => null, 'probeError' => null]],
                        'deadLetter' => ['enabled' => true],
                        'tenancy' => ['header' => 'tenant', 'tenants' => ['tenant_a', 'tenant_b']],
                    ], JSON_THROW_ON_ERROR);
                }

                $count = $this->failures[$tenant] ?? 0;

                return json_encode($base + [
                    'tenant' => $tenant,
                    'channels' => [['name' => 'tenant_queue', 'kind' => 'async', 'ready' => $this->depths[$tenant] ?? 0, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null]],
                    'deadLetters' => [
                        'total' => $count,
                        'groups' => $count === 0 ? [] : [['failureId' => 'same-failure', 'messageName' => 'order.place', 'exceptionClass' => 'RuntimeException', 'exceptionFile' => 'a.php', 'exceptionLine' => 1, 'exceptionMessage' => 'boom', 'count' => $count, 'firstFailedAt' => gmdate('c'), 'lastFailedAt' => gmdate('c')]],
                        'recent' => [],
                    ],
                    'projections' => $this->projections[$tenant] ?? [],
                ], JSON_THROW_ON_ERROR);
            }
        };
        $this->services['orders'] = $this->serviceApplication('orders', [$this->orders::class], [$this->orders], false);

        return $client;
    }

    private function observe(KernelBrowser $client, string $uri): Crawler
    {
        foreach ([1, 2] as $round) {
            $client->request('POST', '/refresh');
            $this->runService($this->services['orders'], 'orders');
            $this->runPulseWorker($client->getKernel());
        }

        return $client->request('GET', $uri);
    }

    private function statusOf(Crawler $crawler, string $scope, string $projection): string
    {
        return $crawler->filter(sprintf('[data-projections-section="%s"] tr[data-projection="%s"] [data-projection-status]', $scope, $projection))->attr('data-projection-status');
    }

    private function projection(string $name, int $events, string $digest = 'digest'): array
    {
        return ['name' => $name, 'mode' => 'asynchronous', 'partitioned' => false, 'asyncChannel' => 'tickets_channel', 'initialization' => 'initialized', 'positionDigest' => $digest, 'eventsBehind' => ['events' => $events, 'atLeast' => false], 'partitions' => null, 'unavailable' => null];
    }
}
