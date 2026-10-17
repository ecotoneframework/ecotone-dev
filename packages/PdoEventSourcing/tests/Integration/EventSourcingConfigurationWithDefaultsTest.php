<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class EventSourcingConfigurationWithDefaultsTest extends EventSourcingMessagingTestCase
{
    public function test_service_context_declaring_event_sourcing_configuration_can_return_with_defaults(): void
    {
        $configuration = new class () {
            #[ServiceContext]
            public function eventSourcing(): EventSourcingConfiguration
            {
                return EventSourcingConfiguration::withDefaults()->withLoadBatchSize(10);
            }
        };

        $ecotone = $this->bootstrapFlowTesting(
            [$configuration::class],
            [$configuration, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            environment: 'prod',
        );

        $eventStore = $ecotone->getGateway(EventStore::class);
        $eventStore->appendTo('ecotone_event_stream', [Event::createWithType('order.placed', ['orderId' => 'order-1'])]);

        self::assertCount(1, $eventStore->load('ecotone_event_stream'));
    }
}
