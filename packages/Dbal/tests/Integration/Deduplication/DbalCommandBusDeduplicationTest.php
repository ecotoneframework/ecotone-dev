<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus\CustomHeaderDeduplicatedCommandBus;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus\DeduplicatedCommandBus;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus\ExpressionDeduplicatedCommandBus;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus\IsolatedCommandBusOne;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus\IsolatedCommandBusTwo;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus\OrderService;

/**
 * @internal
 */
/**
 * licence Enterprise
 * @internal
 */
final class DbalCommandBusDeduplicationTest extends DbalMessagingTestCase
{
    public function test_deduplicating_commands_with_default_message_id_via_command_bus()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, DeduplicatedCommandBus::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBus = $ecotoneLite->getGateway(DeduplicatedCommandBus::class);
        $messageId = Uuid::v7()->toRfc4122();

        // Send same command twice with same MESSAGE_ID
        $commandBus->sendWithRouting('order.place', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => $messageId]);
        $commandBus->sendWithRouting('order.place', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => $messageId]);

        // Should only be processed once due to deduplication
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('order.getCount'));
        $this->assertEquals(['coffee'], $ecotoneLite->sendQueryWithRouting('order.getProcessedOrders'));
    }

    public function test_deduplicating_commands_with_custom_header_via_command_bus()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, CustomHeaderDeduplicatedCommandBus::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBus = $ecotoneLite->getGateway(CustomHeaderDeduplicatedCommandBus::class);
        $customOrderId = 'order-123';

        // Send same command twice with same custom header
        $commandBus->sendWithRouting('order.place', 'coffee', metadata: ['customOrderId' => $customOrderId]);
        $commandBus->sendWithRouting('order.place', 'tea', metadata: ['customOrderId' => $customOrderId]);

        // Should only be processed once due to deduplication
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('order.getCount'));
        $this->assertEquals(['coffee'], $ecotoneLite->sendQueryWithRouting('order.getProcessedOrders'));
    }

    public function test_deduplicating_commands_with_expression_via_command_bus()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ExpressionDeduplicatedCommandBus::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBus = $ecotoneLite->getGateway(ExpressionDeduplicatedCommandBus::class);
        $orderId = 'order-456';

        // Send same command twice with same orderId header
        $commandBus->sendWithRouting('order.place', 'coffee', metadata: ['orderId' => $orderId]);
        $commandBus->sendWithRouting('order.place', 'tea', metadata: ['orderId' => $orderId]);

        // Should only be processed once due to deduplication
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('order.getCount'));
        $this->assertEquals(['coffee'], $ecotoneLite->sendQueryWithRouting('order.getProcessedOrders'));
    }

    public function test_allowing_different_commands_with_different_deduplication_values()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ExpressionDeduplicatedCommandBus::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBus = $ecotoneLite->getGateway(ExpressionDeduplicatedCommandBus::class);

        // Send commands with different orderId headers
        $commandBus->sendWithRouting('order.place', 'coffee', metadata: ['orderId' => 'order-123']);
        $commandBus->sendWithRouting('order.place', 'tea', metadata: ['orderId' => 'order-456']);
        $commandBus->sendWithRouting('order.cancel', 'order-789', metadata: ['orderId' => 'order-789']);

        // All should be processed as they have different deduplication values
        $this->assertEquals(3, $ecotoneLite->sendQueryWithRouting('order.getCount'));
        $this->assertEquals(['coffee', 'tea', 'cancelled-order-789'], $ecotoneLite->sendQueryWithRouting('order.getProcessedOrders'));
    }

    public function test_deduplication_works_across_different_command_types()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ExpressionDeduplicatedCommandBus::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBus = $ecotoneLite->getGateway(ExpressionDeduplicatedCommandBus::class);
        $orderId = 'order-123';

        // Send different command types with same orderId
        $commandBus->sendWithRouting('order.place', 'coffee', metadata: ['orderId' => $orderId]);
        $commandBus->sendWithRouting('order.cancel', $orderId, metadata: ['orderId' => $orderId]);

        // Only first command should be processed due to deduplication
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('order.getCount'));
        $this->assertEquals(['coffee'], $ecotoneLite->sendQueryWithRouting('order.getProcessedOrders'));
    }

    public function test_using_deduplication_in_multiple_command_buses()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, ExpressionDeduplicatedCommandBus::class, CustomHeaderDeduplicatedCommandBus::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBusOne = $ecotoneLite->getGateway(ExpressionDeduplicatedCommandBus::class);
        $commandBusTwo = $ecotoneLite->getGateway(CustomHeaderDeduplicatedCommandBus::class);
        $orderId = 'order-123';

        // Send different command types with same orderId
        $commandBusOne->sendWithRouting('order.place', 'coffee', metadata: ['orderId' => $orderId, 'customOrderId' => $orderId]);
        $commandBusTwo->sendWithRouting('order.place', 'coffee', metadata: ['orderId' => $orderId, 'customOrderId' => $orderId]);

        // Only first command should be processed due to deduplication
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('order.getCount'));
    }

    public function test_throws_licensing_exception_when_using_deduplicated_on_interface_without_enterprise_license()
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Deduplicated attribute on interfaces/gateways');

        EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, DeduplicatedCommandBus::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ])
            // No license key provided - should throw exception
        );
    }

    public function test_deduplication_isolation_with_tracking_names()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, IsolatedCommandBusOne::class, IsolatedCommandBusTwo::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBusOne = $ecotoneLite->getGateway(IsolatedCommandBusOne::class);
        $commandBusTwo = $ecotoneLite->getGateway(IsolatedCommandBusTwo::class);
        $orderId = 'order-123';

        // Send same command with same orderId through different buses with different tracking names
        $commandBusOne->sendWithRouting('order.place', 'coffee', metadata: ['orderId' => $orderId]);
        $commandBusTwo->sendWithRouting('order.place', 'tea', metadata: ['orderId' => $orderId]);

        // Both should be processed because they have different tracking names (isolation)
        $this->assertEquals(2, $ecotoneLite->sendQueryWithRouting('order.getCount'));
        $this->assertEquals(['coffee', 'tea'], $ecotoneLite->sendQueryWithRouting('order.getProcessedOrders'));
    }

    public function test_deduplication_within_same_tracking_name()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, IsolatedCommandBusOne::class],
            [
                new OrderService(),
                DbalConnectionFactory::class => $this->getConnectionFactory(true),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDeduplication(true),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE
        );

        $commandBusOne = $ecotoneLite->getGateway(IsolatedCommandBusOne::class);
        $orderId = 'order-456';

        // Send same command twice through same bus with same tracking name
        $commandBusOne->sendWithRouting('order.place', 'coffee', metadata: ['orderId' => $orderId]);
        $commandBusOne->sendWithRouting('order.place', 'tea', metadata: ['orderId' => $orderId]);

        // Only first should be processed due to deduplication within same tracking name
        $this->assertEquals(1, $ecotoneLite->sendQueryWithRouting('order.getCount'));
        $this->assertEquals(['coffee'], $ecotoneLite->sendQueryWithRouting('order.getProcessedOrders'));
    }
}
