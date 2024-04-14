<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

final class MessagingConfiguration
{
    #[ServiceContext]
    public function enableDocumentStoreAggregates()
    {
        /** This also works for state-stored sagas */
        return DbalConfiguration::createWithDefaults()
            ->withDocumentStore(
                enableDocumentStoreStandardRepository: true,
            );
    }

    #[ServiceContext]
    public function databaseChannel()
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }
}