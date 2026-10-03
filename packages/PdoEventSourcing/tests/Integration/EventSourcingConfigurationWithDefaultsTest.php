<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
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
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true)]),
        );

        $eventStore = $ecotone->getGateway(EventStore::class);
        $streamName = Uuid::v7()->toRfc4122();
        $eventStore->appendTo($streamName, [Event::createWithType('order.placed', ['orderId' => 'order-1'])]);

        self::assertCount(1, $eventStore->load($streamName));
    }
}
