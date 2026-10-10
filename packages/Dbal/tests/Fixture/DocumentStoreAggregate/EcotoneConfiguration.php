<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;

/**
 * licence Apache-2.0
 */
class EcotoneConfiguration
{
    #[ServiceContext]
    public function getDbalConfiguration(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
                ->withDocumentStore(enableDocumentStoreStateStoredRepository: true);
    }
}
