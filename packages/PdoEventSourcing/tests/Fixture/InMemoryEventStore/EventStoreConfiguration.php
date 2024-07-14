<?php

namespace Test\Ecotone\EventSourcing\Fixture\InMemoryEventStore;

use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
class EventStoreConfiguration
{
    #[ServiceContext]
    public function configureProjection()
    {
        return \Ecotone\EventSourcing\EventSourcingConfiguration::createInMemory();
    }
}
