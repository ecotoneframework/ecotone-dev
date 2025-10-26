<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\DeadLetter\ErrorConfigurationContext;
use Test\Ecotone\Amqp\Fixture\DeadLetter\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ErrorChannelTest extends AmqpMessagingTestCase
{
    public function test_exception_handling_with_retries_and_dead_letter(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), ...$this->getConnectionFactoryReferences()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Amqp\Fixture\DeadLetter']),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotone
            ->sendCommandWithRoutingKey('order.register', 'coffee')
            ->run('correctOrders')
            ->run('incorrectOrdersEndpoint')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getIncorrectOrderAmount'));

        $ecotone
            ->run('correctOrders')
            ->run('incorrectOrdersEndpoint')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getIncorrectOrderAmount'));

        $ecotone
            ->run('correctOrders')
            ->run('incorrectOrdersEndpoint')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getIncorrectOrderAmount'));
    }

    public function test_pointing_amqp_directly_to_dead_letter(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [OrderService::class],
            containerOrAvailableServices: [new OrderService(1), ...$this->getConnectionFactoryReferences()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($amqpDeadLetter = 'amqp_dead_letter')
                        ->withReceiveTimeout(1),
                    AmqpBackedMessageChannelBuilder::create(ErrorConfigurationContext::INPUT_CHANNEL)
                        ->withReceiveTimeout(1),
                    PollingMetadata::create(ErrorConfigurationContext::INPUT_CHANNEL)
                        ->setErrorChannelName($amqpDeadLetter),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE])),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotone
            ->sendCommandWithRoutingKey('order.register', 'coffee')
            ->run(ErrorConfigurationContext::INPUT_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(1, 1000, false));
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $ecotone
            ->run($amqpDeadLetter, ExecutionPollingMetadata::createWithTestingSetup(1, 1000, false))
        ;
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_throwing_exception_if_using_non_existing_dead_letter_channel(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->bootstrapFlowTesting(
            classesToResolve: [OrderService::class],
            containerOrAvailableServices: [new OrderService(1), ...$this->getConnectionFactoryReferences()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create(ErrorConfigurationContext::INPUT_CHANNEL)
                        ->withReceiveTimeout(1),
                    PollingMetadata::create(ErrorConfigurationContext::INPUT_CHANNEL)
                        ->setErrorChannelName('amqp_dead_letter'),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE])),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    public function test_exception_handling_with_retries(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [new \Test\Ecotone\Amqp\Fixture\ErrorChannel\OrderService(), ...$this->getConnectionFactoryReferences()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Amqp\Fixture\ErrorChannel']),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotone
            ->sendCommandWithRoutingKey('order.register', 'coffee')
            ->run('correctOrders')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $ecotone
            ->run('correctOrders')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $ecotone
            ->run('correctOrders')
        ;

        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }
}
