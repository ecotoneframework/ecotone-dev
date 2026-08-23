<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

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
