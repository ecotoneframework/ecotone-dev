<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\ClosureInAttribute;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Attributes\RequiresPhp;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ClosureInAttribute\ClosureDeduplicatedHandler;
use Test\Ecotone\Dbal\Fixture\ClosureInAttribute\PersonClosureParameterApi;
use Test\Ecotone\Dbal\Fixture\ClosureInAttribute\PolicyDrivenDeduplicatedHandler;
use Test\Ecotone\Dbal\Fixture\ClosureInAttribute\TenantClosurePoller;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;

/**
 * licence Enterprise
 * @internal
 */
#[RequiresPhp('>= 8.5')]
final class ClosureExpressionDbalTest extends DbalMessagingTestCase
{
    public function test_deduplication_with_closure_expression(): void
    {
        $handler = new ClosureDeduplicatedHandler();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ClosureDeduplicatedHandler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE])),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRoutingKey('closureDedup.handle', 'test', metadata: ['orderId' => 'order-123']);
        $ecotoneLite->sendCommandWithRoutingKey('closureDedup.handle', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('closureDedup.getCallCount'));

        $ecotoneLite->sendCommandWithRoutingKey('closureDedup.handle', 'test', metadata: ['orderId' => 'order-456']);
        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('closureDedup.getCallCount'));
    }

    public function test_deduplication_closure_expression_receives_attribute_declared_on_handler(): void
    {
        $handler = new PolicyDrivenDeduplicatedHandler();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [PolicyDrivenDeduplicatedHandler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE])),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRoutingKey('policyDedup.perCustomer', 'test', metadata: ['customerId' => 'customer-1', 'orderId' => 'order-1']);
        $ecotoneLite->sendCommandWithRoutingKey('policyDedup.perCustomer', 'test', metadata: ['customerId' => 'customer-1', 'orderId' => 'order-2']);

        $this->assertSame(
            ['order-1'],
            $ecotoneLite->sendQueryWithRouting('policyDedup.handledPerCustomer'),
            'DedupPolicy with customer scope was injected into closure, so both orders of same customer deduplicate to one'
        );

        $ecotoneLite->sendCommandWithRoutingKey('policyDedup.perOrder', 'test', metadata: ['customerId' => 'customer-2', 'orderId' => 'order-3']);
        $ecotoneLite->sendCommandWithRoutingKey('policyDedup.perOrder', 'test', metadata: ['customerId' => 'customer-2', 'orderId' => 'order-4']);

        $this->assertSame(
            ['order-3', 'order-4'],
            $ecotoneLite->sendQueryWithRouting('policyDedup.handledPerOrder'),
            'Same closure with order scoped DedupPolicy deduplicates per order, proving the injected attribute drives the key'
        );
    }

    public function test_deduplication_closure_expression_throws_licensing_exception_on_bootstrap_without_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ClosureDeduplicatedHandler::class],
            containerOrAvailableServices: [new ClosureDeduplicatedHandler(), DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE])),
        );
    }

    public function test_dbal_parameter_closure_expression_throws_licensing_exception_on_bootstrap_without_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [PersonClosureParameterApi::class],
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE])),
        );
    }

    public function test_dbal_parameter_with_method_level_closure_expression(): void
    {
        $ecotoneLite = $this->bootstrapWithBusinessMethods();
        $personApi = $ecotoneLite->getGateway(PersonClosureParameterApi::class);

        $personApi->insertWithMethodLevelClosure(1, 'John', 'Doe');

        $this->assertSame('John Doe', $personApi->getNameById(1));
    }

    public function test_dbal_parameter_with_parameter_level_closure_expression(): void
    {
        $ecotoneLite = $this->bootstrapWithBusinessMethods();
        $personApi = $ecotoneLite->getGateway(PersonClosureParameterApi::class);

        $personApi->insertWithParameterLevelClosure(2, 'MARIA');

        $this->assertSame('maria', $personApi->getNameById(2));
    }

    public function test_dbal_parameter_closure_binds_context_variables_and_default_values(): void
    {
        $ecotoneLite = $this->bootstrapWithBusinessMethods();
        $personApi = $ecotoneLite->getGateway(PersonClosureParameterApi::class);

        $personApi->insertWithTitledName(3, 'John');

        $this->assertSame('Sir John', $personApi->getNameById(3));
    }

    public function test_tenant_resolver_with_closure_expression(): void
    {
        $poller = new TenantClosurePoller([
            ['source' => 'tenant_a', 'payload' => 'first'],
        ]);
        $receiver = $this->newReceiver();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [TenantClosurePoller::class, $receiver::class],
            [$poller, $receiver, 'tenant_a_connection' => new FakeConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    PollingMetadata::create('externalEventPoller')
                        ->setExecutionAmountLimit(1)
                        ->setHandledMessageLimit(1),
                    PollingMetadata::create('external_processing')
                        ->setExecutionAmountLimit(1)
                        ->setHandledMessageLimit(1),
                    MultiTenantConfiguration::createWithDefaultConnection(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_a_connection'],
                        'tenant_a_connection',
                        DbalConnectionFactory::class,
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withClearAndFlushObjectManagerOnCommandBus(false)
                        ->withDeduplication(false),
                ]),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('external_processing'),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->run('externalEventPoller', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
        $ecotone->run('external_processing', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $capturedHeaders = $ecotone->sendQueryWithRouting('lastCapturedHeaders');
        $this->assertNotNull($capturedHeaders);
        $this->assertSame('tenant_a', $capturedHeaders['tenant'] ?? null);
    }

    private function bootstrapWithBusinessMethods(): FlowTestSupport
    {
        $this->setupUserTable();

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [PersonClosureParameterApi::class],
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE])),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function newReceiver(): object
    {
        return new class () {
            private array $captured = [];

            #[Asynchronous('external_processing')]
            #[CommandHandler('externalEventArrived', endpointId: 'externalEventArrivedEndpoint')]
            public function handle(mixed $payload, #[Headers] array $headers): void
            {
                $this->captured[] = $headers;
            }

            #[QueryHandler('lastCapturedHeaders')]
            public function lastCapturedHeaders(): ?array
            {
                return array_shift($this->captured);
            }
        };
    }
}
