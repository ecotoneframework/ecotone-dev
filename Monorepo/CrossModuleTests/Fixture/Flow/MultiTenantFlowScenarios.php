<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Test\Flow\ExpectedSpan;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowStage;
use Test\Ecotone\EventSourcing\SecondaryTestDatabaseConnectionFactory;

/**
 * licence Apache-2.0
 */
final class MultiTenantFlowScenarios
{
    /**
     * @return list<FlowScenario>
     */
    public static function all(): array
    {
        return [
            self::commandsOfTwoTenantsConsumedFromTheirOwnDatabases(),
        ];
    }

    private static function commandsOfTwoTenantsConsumedFromTheirOwnDatabases(): FlowScenario
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };
        $sentFor = fn (string $tenant) => ExpectedSpan::named('send command')->asRoot()->withAttributes(['tenant' => $tenant])->containing(
            ExpectedSpan::named('send orders')->ofKind(SpanKind::Producer),
        );
        $handledFor = fn (string $tenant) => ExpectedSpan::named('send command')->asRoot()->withAttributes(['tenant' => $tenant])->containing(
            ExpectedSpan::named('send orders')->containing(
                ExpectedSpan::named('receive orders')->ofKind(SpanKind::Consumer)->containing(
                    ExpectedSpan::named('process class@anonymous::place')->withAttributes([SpanAttribute::ENDPOINT_ID => 'order.placing']),
                ),
            ),
        );

        return new FlowScenario('commands of two tenants consumed from their own databases', [$orders::class], [
            $orders,
            'tenant_a_connection' => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone'),
            'tenant_b_connection' => new DbalConnectionFactory(SecondaryTestDatabaseConnectionFactory::resolveDsn()),
        ], [
            MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection']),
            DbalBackedMessageChannelBuilder::create('orders'),
        ], [
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '1', metadata: [...$metadata, 'tenant' => 'tenant_a']))
                ->expect($sentFor('tenant_a'))
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone, array $metadata) => $ecotone->sendCommandWithRouting('order.place', '2', metadata: [...$metadata, 'tenant' => 'tenant_b']))
                ->expect($sentFor('tenant_b'))
                ->leavingWorkPending(),
            FlowStage::when(fn (FlowTestSupport $ecotone) => $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup()))
                ->expect($handledFor('tenant_a'), $handledFor('tenant_b')),
        ]);
    }
}
