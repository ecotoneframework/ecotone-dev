<?php

namespace Test\Ecotone\Dbal\Fixture\InMemoryDocumentStore;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\ServiceContext;

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
