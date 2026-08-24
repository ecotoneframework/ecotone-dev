<?php

namespace Test\Ecotone\Dbal\Fixture\InMemoryDocumentStore;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;

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
