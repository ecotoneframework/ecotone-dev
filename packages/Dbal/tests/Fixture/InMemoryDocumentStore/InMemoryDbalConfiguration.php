<?php

namespace Test\Ecotone\Dbal\Fixture\InMemoryDocumentStore;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

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
