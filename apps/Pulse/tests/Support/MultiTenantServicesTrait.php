<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
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
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Test\LicenceTesting;
use RuntimeException;

trait MultiTenantServicesTrait
{
    use DeadLetterServicesTrait;

    private const TENANT_WORK_CHANNEL = 'pulse_tenant_work';

    private object $tenantWork;

    private function multiTenantService(string $service): FlowTestSupport
    {
        $this->tenantWork = $work = new class () {
            public bool $failing = true;

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

            #[CommandHandler('order.audit', endpointId: 'pulse.tenant.order.audit.endpoint')]
            public function audit(array $order): void
            {
            }
        };
        $dsn = $_SERVER['PULSE_TRANSPORT_DSN'];
        $application = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$work::class],
            containerOrAvailableServices: [
                $work,
                'pulse_connection' => OpenedConnections::factory($dsn),
                'tenant_a_connection' => OpenedConnections::factory($dsn),
                'tenant_b_connection' => OpenedConnections::factory((string) getenv('SECONDARY_DATABASE_DSN')),
            ],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create($this->serviceChannels[$service], 'pulse_connection')->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create($this->pulseChannel, 'pulse_connection'),
                SimpleMessageChannelBuilder::createQueueChannel(self::TENANT_WORK_CHANNEL),
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
        $application->runConsoleCommand('ecotone:migration:database:delete', ['force' => true]);
        $application->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

        return $application;
    }

    private function failOrderOfTenant(FlowTestSupport $service, string $tenant, int $orderId): void
    {
        $service->sendCommandWithRouting('order.place', ['orderId' => $orderId], metadata: ['tenant' => $tenant]);
        $service->run(self::TENANT_WORK_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(10, 100, false)->withMemoryLimitInMegabytes(4096));
    }

    private function consumeTenantWork(FlowTestSupport $service): void
    {
        $service->run(self::TENANT_WORK_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(10, 100, false)->withMemoryLimitInMegabytes(4096));
    }

    private function auditOfTenant(FlowTestSupport $service, string $tenant, int $orderId, string $traceId): void
    {
        $service->sendCommandWithRouting('order.audit', ['orderId' => $orderId], metadata: ['tenant' => $tenant, MessageHeaders::TRACEPARENT => (new SpanContext($traceId, 'b7ad6b7169203331'))->toTraceparent()]);
    }
}
