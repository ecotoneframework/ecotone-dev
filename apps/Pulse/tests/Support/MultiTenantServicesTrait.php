<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDeployment;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

trait MultiTenantServicesTrait
{
    use DeadLetterServicesTrait;

    private const TENANT_WORK_CHANNEL = 'pulse_tenant_work';

    private object $tenantWork;

    private object $tenantProjections;

    private object $tenantKickoffProjections;

    private function multiTenantService(string $service, ?DbalConnectionFactory $unpreparedTenantB = null): FlowTestSupport
    {
        $this->tenantWork = $work = new class () {
            public bool $failing = true;

            /**
             * @var list<string>
             */
            public array $queued = [];

            /**
             * @var list<string>
             */
            public array $handled = [];

            #[Asynchronous('pulse_tenant_work')]
            #[CommandHandler('order.place', endpointId: 'pulse.tenant.order.place.endpoint')]
            public function place(array $order, #[Header('tenant')] string $tenant): void
            {
                if ($this->failing) {
                    throw new RuntimeException('place failed for order ' . $order['orderId']);
                }

                $this->handled[] = $tenant . ':' . $order['orderId'];
            }

            #[Asynchronous('pulse_tenant_queue')]
            #[CommandHandler('order.queue', endpointId: 'pulse.tenant.order.queue.endpoint')]
            public function queue(array $order, #[Header('tenant')] string $tenant): void
            {
                $this->queued[] = $tenant . ':' . $order['orderId'];
            }

            #[CommandHandler('order.audit', endpointId: 'pulse.tenant.order.audit.endpoint')]
            public function audit(array $order): void
            {
            }
        };
        $this->tenantProjections = $projections = new #[Projection('tenant_orders'), FromStream('tenant_orders_stream')] class () {
            /**
             * @var list<string>
             */
            public array $projected = [];

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                $this->projected[] = $event['orderId'];
            }
        };
        $this->tenantKickoffProjections = $kickoffProjections = new #[Projection('tenant_kickoff_orders'), FromStream('tenant_orders_stream'), ProjectionDeployment(manualKickOff: true)] class () {
            /**
             * @var list<string>
             */
            public array $projected = [];

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                $this->projected[] = $event['orderId'];
            }
        };
        $dsn = $_SERVER['PULSE_TRANSPORT_DSN'];
        $connections = [
            'pulse_connection' => OpenedConnections::factory($dsn),
            'tenant_a_connection' => OpenedConnections::factory($dsn),
            'tenant_b_connection' => OpenedConnections::factory((string) getenv('SECONDARY_DATABASE_DSN')),
        ];
        $bootstrap = fn (array $connections): FlowTestSupport => EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$work::class, $projections::class, $kickoffProjections::class],
            containerOrAvailableServices: [$work, $projections, $kickoffProjections, ...$connections],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create($this->serviceChannels[$service], 'pulse_connection')->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create($this->pulseChannel, 'pulse_connection'),
                SimpleMessageChannelBuilder::createQueueChannel(self::TENANT_WORK_CHANNEL),
                DbalBackedMessageChannelBuilder::create('pulse_tenant_queue'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: $this->pulseChannel),
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection']),
                ServiceConfiguration::createWithDefaults()->withServiceName($service)->withDefaultErrorChannel('dbal_dead_letter'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true),
                FlowTrackingConfiguration::createWithDefaults(),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );
        $application = $bootstrap($connections);
        $application->runConsoleCommand('ecotone:migration:database:delete', ['force' => true]);
        $application->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

        return $unpreparedTenantB === null ? $application : $bootstrap([...$connections, 'tenant_b_connection' => $unpreparedTenantB]);
    }

    private function failOrderOfTenant(FlowTestSupport $service, string $tenant, int $orderId): void
    {
        $service->sendCommandWithRouting('order.place', ['orderId' => $orderId], metadata: ['tenant' => $tenant]);
        $service->run(self::TENANT_WORK_CHANNEL, ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function consumeTenantWork(FlowTestSupport $service): void
    {
        $service->run(self::TENANT_WORK_CHANNEL, ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function auditOfTenant(FlowTestSupport $service, string $tenant, int $orderId, string $traceId): void
    {
        $service->sendCommandWithRouting('order.audit', ['orderId' => $orderId], metadata: ['tenant' => $tenant, MessageHeaders::TRACEPARENT => (new SpanContext($traceId, 'b7ad6b7169203331'))->toTraceparent()]);
    }

    private function pulseWithTenants(string $narrowing = ''): KernelBrowser
    {
        return $this->pulseWithTenantServices($narrowing, null);
    }

    private function pulseWithTenantServices(string $narrowing, ?DbalConnectionFactory $unpreparedTenantB): KernelBrowser
    {
        $suffix = (string) getmypid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->serviceChannels = ['orders' => 'orders_' . $suffix, 'billing' => 'billing_' . $suffix];
        $this->configurePulse([
            'PULSE_CHANNEL' => $this->pulseChannel,
            'PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders'] . $narrowing . ',billing:' . $this->serviceChannels['billing'],
        ]);
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->services = ['orders' => $this->multiTenantService('orders', $unpreparedTenantB)];
        $this->services['billing'] = $this->serviceWithoutDeadLetters('billing');

        return $client;
    }

    private function refreshedView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->deliver($client);
        $client->request('POST', '/refresh');
        $this->deliver($client);

        return $client->request('GET', $uri);
    }

    private function deliver(KernelBrowser $client): void
    {
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());
    }
}
