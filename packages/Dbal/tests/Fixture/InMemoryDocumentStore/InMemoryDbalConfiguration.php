<?php

namespace Test\Ecotone\Dbal\Fixture\InMemoryDocumentStore;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
final class InMemoryDbalConfiguration
{
    #[ServiceContext]
    public function configuration()
    {
        return DbalConfiguration::createWithDefaults()
                    ->withDocumentStore(inMemoryDocumentStore: true);
    }
}
