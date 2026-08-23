<?php

namespace Test\Ecotone\EventSourcing\Fixture\InMemoryEventStore;

use Ecotone\Api\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
class EventStoreConfiguration
{
    #[ServiceContext]
    public function configureProjection()
    {
        return \Ecotone\EventSourcing\Api\ExtensionObject\EventSourcingConfiguration::createInMemory();
    }
}
