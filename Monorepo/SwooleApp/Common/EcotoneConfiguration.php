<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Common;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()->withDocumentStore(isDocumentStoreEnabled: true, enableDocumentStoreStateStoredRepository: true, documentStoreRelatedAggregates: [Wallet::class]);
    }

    #[ServiceContext]
    public function eventSourcing(): EventSourcingConfiguration
    {
        return EventSourcingConfiguration::createWithDefaults();
    }

    #[ServiceContext]
    public function notifications(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('notifications');
    }
}
