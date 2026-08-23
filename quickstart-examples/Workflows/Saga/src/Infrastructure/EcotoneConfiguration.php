<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Infrastructure;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseChannel()
    {
        return DbalBackedMessageChannelBuilder::create('async_saga');
    }

    #[ServiceContext]
    public function documentStoreRepository()
    {
        return DbalConfiguration::createWithDefaults()
                ->withDocumentStore(enableDocumentStoreStateStoredRepository: true);
    }
}