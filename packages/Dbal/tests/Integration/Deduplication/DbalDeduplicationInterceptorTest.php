<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class DbalDeduplicationInterceptorTest extends DbalMessagingTestCase
{
    public function test_not_deduplicating_for_different_endpoints()
    {
        $handler = new class () {
            private int $called = 0;

            #[Deduplicated]
            #[CommandHandler('endpoint1', endpointId: 'handler_endpoint1')]
            public function handleEndpoint1(): void
            {
                $this->called++;
            }

            #[Deduplicated]
            #[CommandHandler('endpoint2', endpointId: 'handler_endpoint2')]
            public function handleEndpoint2(): void
            {
                $this->called++;
            }

            #[QueryHandler('getCallCount')]
            public function getCallCount(): int
            {
                return $this->called;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($handler)],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        $messageId = '1';
        $ecotoneLite
            ->sendCommandWithRoutingKey('endpoint1', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
            ->sendCommandWithRoutingKey('endpoint2', metadata: [MessageHeaders::MESSAGE_ID => $messageId]);

        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('getCallCount'));
    }

    public function test_not_handling_same_message_twice()
    {
        $handler = new class () {
            private int $called = 0;

            #[Deduplicated]
            #[CommandHandler('endpoint1', endpointId: 'handler_endpoint1')]
            public function handle(): void
            {
                $this->called++;
            }

            #[QueryHandler('getCallCount')]
            public function getCallCount(): int
            {
                return $this->called;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($handler)],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        $messageId = '1';
        $ecotoneLite
            ->sendCommandWithRoutingKey('endpoint1', metadata: [MessageHeaders::MESSAGE_ID => $messageId])
            ->sendCommandWithRoutingKey('endpoint1', metadata: [MessageHeaders::MESSAGE_ID => $messageId]);

        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));
    }

    public function test_deduplicating_with_header_expression()
    {
        $handler = new class () {
            private int $called = 0;

            #[Deduplicated(expression: "headers['orderId']")]
            #[CommandHandler('endpoint1', endpointId: 'handler_endpoint1')]
            public function handle(): void
            {
                $this->called++;
            }

            #[QueryHandler('getCallCount')]
            public function getCallCount(): int
            {
                return $this->called;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($handler)],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        // First call with orderId header
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));

        // Second call with same orderId header (should be deduplicated)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));

        // Third call with different orderId header (should be processed)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'test', metadata: ['orderId' => 'order-456']);
        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('getCallCount'));
    }

    public function test_deduplicating_with_payload_expression()
    {
        $handler = new class () {
            private int $called = 0;

            #[Deduplicated(expression: 'payload')]
            #[CommandHandler('endpoint1', endpointId: 'handler_endpoint1')]
            public function handle(): void
            {
                $this->called++;
            }

            #[QueryHandler('getCallCount')]
            public function getCallCount(): int
            {
                return $this->called;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($handler)],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        // First call with specific payload
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'unique-payload-1');
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));

        // Second call with same payload (should be deduplicated)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'unique-payload-1');
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));

        // Third call with different payload (should be processed)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'unique-payload-2');
        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('getCallCount'));
    }

    public function test_deduplicating_with_complex_expression()
    {
        $handler = new class () {
            private int $called = 0;

            #[Deduplicated(expression: "headers['customerId'] ~ '_' ~ payload")]
            #[CommandHandler('endpoint1', endpointId: 'handler_endpoint1')]
            public function handle(): void
            {
                $this->called++;
            }

            #[QueryHandler('getCallCount')]
            public function getCallCount(): int
            {
                return $this->called;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($handler)],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        // First call
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'order-data', metadata: ['customerId' => 'customer-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));

        // Second call with same combination (should be deduplicated)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'order-data', metadata: ['customerId' => 'customer-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));

        // Third call with different customer but same payload (should be processed)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'order-data', metadata: ['customerId' => 'customer-456']);
        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('getCallCount'));
    }

    public function test_deduplicating_with_tracking_name_isolation()
    {
        $handler = new class () {
            private int $trackingOneCalled = 0;
            private int $trackingTwoCalled = 0;

            #[Deduplicated(expression: "headers['orderId']", trackingName: 'tracking_one')]
            #[CommandHandler('endpoint1', endpointId: 'handler_endpoint1')]
            public function handleTrackingOne(): void
            {
                $this->trackingOneCalled++;
            }

            #[Deduplicated(expression: "headers['orderId']", trackingName: 'tracking_two')]
            #[CommandHandler('endpoint2', endpointId: 'handler_endpoint2')]
            public function handleTrackingTwo(): void
            {
                $this->trackingTwoCalled++;
            }

            #[QueryHandler('getTrackingOneCallCount')]
            public function getTrackingOneCallCount(): int
            {
                return $this->trackingOneCalled;
            }

            #[QueryHandler('getTrackingTwoCallCount')]
            public function getTrackingTwoCallCount(): int
            {
                return $this->trackingTwoCalled;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($handler)],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        // First call with tracking_one
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getTrackingOneCallCount'));
        $this->assertEquals(0, $ecotoneLite->sendQueryWithRouting('getTrackingTwoCallCount'));

        // Second call with same orderId but different tracking name (should be processed)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint2', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getTrackingOneCallCount'));
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getTrackingTwoCallCount'));

        // Third call with same orderId and same tracking name as first (should be deduplicated)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getTrackingOneCallCount'));
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getTrackingTwoCallCount'));
    }

    public function test_deduplicating_with_same_tracking_name_and_different_endpoint_id()
    {
        $handler = new class () {
            private int $called = 0;

            #[Deduplicated(expression: "headers['orderId']", trackingName: 'custom_tracking')]
            #[CommandHandler('endpoint1', endpointId: 'handler_endpoint1')]
            public function handleEndpoint1(): void
            {
                $this->called++;
            }

            #[Deduplicated(expression: "headers['orderId']", trackingName: 'custom_tracking')]
            #[CommandHandler('endpoint2', endpointId: 'handler_endpoint2')]
            public function handleEndpoint2(): void
            {
                $this->called++;
            }

            #[QueryHandler('getCallCount')]
            public function getCallCount(): int
            {
                return $this->called;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($handler)],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory(true)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
        );

        // First call with custom tracking name
        $ecotoneLite->sendCommandWithRoutingKey('endpoint1', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('getCallCount'));

        // Second call with same orderId and different endpoint but same tracking name (should be deduplicated)
        $ecotoneLite->sendCommandWithRoutingKey('endpoint2', 'test', metadata: ['orderId' => 'order-123']);
        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('getCallCount'));
    }
}
