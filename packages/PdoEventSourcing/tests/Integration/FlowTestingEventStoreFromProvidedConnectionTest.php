<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTestingEventStoreFromProvidedConnectionTest extends EventSourcingMessagingTestCase
{
    public const STREAM = 'flow_testing_orders';

    public function test_events_appended_in_a_flow_test_given_a_connection_are_loaded_by_another_flow_test_on_that_connection(): void
    {
        $this->flowTest(withConnection: true)->getGateway(EventStore::class)->appendTo(self::STREAM, [Event::createWithType('order.placed', ['orderId' => 'o-1'])]);

        $events = $this->flowTest(withConnection: true)->getGateway(EventStore::class)->load(self::STREAM);

        self::assertSame(['order.placed'], array_map(fn (Event $event) => $event->eventName, $events));
    }

    public function test_a_flow_test_without_a_connection_keeps_events_in_memory(): void
    {
        $eventStore = $this->flowTest(withConnection: false)->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [Event::createWithType('order.placed', ['orderId' => 'o-1'])]);

        self::assertCount(1, $eventStore->load(self::STREAM));
        self::assertFalse($this->flowTest(withConnection: false)->getGateway(EventStore::class)->hasStream(self::STREAM));
    }

    private function flowTest(bool $withConnection): FlowTestSupport
    {
        $stream = new #[Stream(FlowTestingEventStoreFromProvidedConnectionTest::STREAM)] class () {
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$stream::class],
            containerOrAvailableServices: $withConnection ? [DbalConnectionFactory::class => self::getConnectionFactory()] : [],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]),
        );
    }
}
