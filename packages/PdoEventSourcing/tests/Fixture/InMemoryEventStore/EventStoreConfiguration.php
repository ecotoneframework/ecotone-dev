<?php

namespace Test\Ecotone\EventSourcing\Fixture\InMemoryEventStore;

use Ecotone\Api\ServiceContext;

/**
 * licence Apache-2.0
 */
class EventStoreConfiguration
{
    #[ServiceContext]
    public function configureProjection()
    {
        return \Ecotone\Api\EventSourcing\EventSourcingConfiguration::createInMemory();
    }
}
