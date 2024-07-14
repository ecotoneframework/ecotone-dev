<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\Deduplication\Converter;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderPlaced;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderService;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderSubscriber;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 */
final class DeduplicationModuleTest extends DbalMessagingTestCase
{
    private const CHANNEL_NAME = 'processOrders';

    public function test_sending_same_command_will_deduplicate_it(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'milk', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);
        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'milk', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk'], $result);
        self::assertCount(1, $result);
    }

    public function test_sending_same_event_will_deduplicate_message(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->publishEvent(event: new OrderPlaced(order: 'milk'), metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);
        $ecotone->publishEvent(event: new OrderPlaced(order: 'milk'), metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);

        self::assertEquals(2, $ecotone->sendQueryWithRouting('order.getCalled'));
    }

    public function test_sending_different_commands_will_not_deduplicate_messages(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'milk', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);
        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'cheese', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de98']);
        $ecotone->run(self::CHANNEL_NAME);

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk', 'cheese'], $result);
        self::assertCount(2, $result);
        self::assertEquals(4, $ecotone->sendQueryWithRouting('order.getCalled'));
    }

    public function test_sending_with_custom_deduplication_key()
    {
        $ecotone = $this->bootstrapEcotone();

        $sameDeduplicationKey = '3e84ff08-b755-4e16-b50d-94818bf9de99';
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'cheese',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk'], $result);
        self::assertCount(1, $result);
    }

    public function test_deduplication_happens_across_endpoint_id()
    {
        $ecotone = $this->bootstrapEcotone();

        $sameDeduplicationKey = '3e84ff08-b755-4e16-b50d-94818bf9de99';
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously2',
            command: 'cheese',
            metadata: ['orderId2' => $sameDeduplicationKey]
        );

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk', 'cheese'], $result);
        self::assertCount(2, $result);
    }

    public function test_deduplicating_within_same_key_for_different_endpoints()
    {
        $ecotone = $this->bootstrapEcotone();

        $sameDeduplicationKey = '3e84ff08-b755-4e16-b50d-94818bf9de99';
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously3',
            command: 'cheese',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk', 'cheese'], $result);
        self::assertCount(2, $result);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), new OrderSubscriber(), new Converter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withDefaultSerializationMediaType(MediaType::createApplicationJson())
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\Deduplication',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
