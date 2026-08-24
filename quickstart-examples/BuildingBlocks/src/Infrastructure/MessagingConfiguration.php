<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;

final class MessagingConfiguration
{
    #[ServiceContext]
    public function enableDocumentStoreAggregates()
    {
        /** This also works for state-stored sagas */
        return DbalConfiguration::createWithDefaults()
            ->withDocumentStore(
                enableDocumentStoreStateStoredRepository: true,
            );
    }

    #[ServiceContext]
    public function databaseChannel()
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }
}