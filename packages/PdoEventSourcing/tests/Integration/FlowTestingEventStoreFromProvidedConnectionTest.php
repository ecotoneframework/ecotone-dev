<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
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

    public function test_a_flow_test_naming_no_packages_and_given_a_connection_stores_events_in_the_database(): void
    {
        $stream = new #[Stream(FlowTestingEventStoreFromProvidedConnectionTest::STREAM)] class () {
        };
        $flowTest = fn () => EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$stream::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => self::getConnectionFactory()],
        );

        $flowTest()->getGateway(EventStore::class)->appendTo(self::STREAM, [Event::createWithType('order.placed', ['orderId' => 'o-1'])]);

        self::assertCount(1, $flowTest()->getGateway(EventStore::class)->load(self::STREAM));
    }

    public function test_a_flow_test_naming_no_packages_keeps_events_in_memory(): void
    {
        $stream = new #[Stream(FlowTestingEventStoreFromProvidedConnectionTest::STREAM)] class () {
        };
        $flowTest = fn () => EcotoneLite::bootstrapFlowTesting(classesToResolve: [$stream::class]);
        $eventStore = $flowTest()->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [Event::createWithType('order.placed', ['orderId' => 'o-1'])]);

        self::assertCount(1, $eventStore->load(self::STREAM));
        self::assertFalse($flowTest()->getGateway(EventStore::class)->hasStream(self::STREAM));
    }

    public function test_an_event_store_configured_on_a_connection_a_flow_test_was_not_given_is_refused_naming_both_ways_out(): void
    {
        try {
            $this->bootstrapFlowTesting(
                configuration: ServiceConfiguration::createWithDefaults()
                    ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                    ->withExtensionObjects([EventSourcingConfiguration::create('reporting_connection')]),
            );
            self::fail('An event store without its connection was accepted');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('The event store is configured on connection `reporting_connection`', $exception->getMessage());
            self::assertStringContainsString("EcotoneLite::bootstrapFlowTesting(containerOrAvailableServices: ['reporting_connection' => new DbalConnectionFactory(\$dsn)])", $exception->getMessage());
            self::assertStringContainsString('leave the EventSourcingConfiguration out of this flow test', $exception->getMessage());
        }
    }

    public function test_an_event_store_on_a_connection_the_flow_test_was_given_stores_its_events_there(): void
    {
        $configuration = ServiceConfiguration::createWithDefaults()
            ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
            ->withExtensionObjects([EventSourcingConfiguration::create('reporting_connection')]);
        $stream = new #[Stream(FlowTestingEventStoreFromProvidedConnectionTest::STREAM, connectionReferenceName: 'reporting_connection')] class () {
        };
        $flowTest = fn () => $this->bootstrapFlowTesting(
            classesToResolve: [$stream::class],
            containerOrAvailableServices: ['reporting_connection' => self::getConnectionFactory()],
            configuration: $configuration,
        );

        $flowTest()->getGateway(EventStore::class)->appendTo(self::STREAM, [Event::createWithType('order.placed', ['orderId' => 'o-1'])]);

        self::assertCount(1, $flowTest()->getGateway(EventStore::class)->load(self::STREAM));
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
