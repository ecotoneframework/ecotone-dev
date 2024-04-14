<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Infrastructure;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

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
                ->withDocumentStore(enableDocumentStoreStandardRepository: true);
    }
}