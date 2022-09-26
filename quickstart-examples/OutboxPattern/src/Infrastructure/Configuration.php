<?php

namespace App\OutboxPattern\Infrastructure;

use App\OutboxPattern\Application\NotificationService;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class Configuration
{
    const ASYNCHRONOUS_CHANNEL = "asynchronous_messages";

    #[ServiceContext]
    public function enableRabbitMQ()
    {
        return DbalBackedMessageChannelBuilder::create(self::ASYNCHRONOUS_CHANNEL);
    }

    #[ServiceContext]
    public function consumerDefinition()
    {
        return PollingMetadata::create(self::ASYNCHRONOUS_CHANNEL)
                    ->setHandledMessageLimit(1)
                    ->setExecutionTimeLimitInMilliseconds(1000);
    }

    #[ServiceContext]
    public function enableDocumentStoreRepository()
    {
        return DbalConfiguration::createWithDefaults()
                ->withDocumentStore(enableDocumentStoreAggregateRepository: true);
    }
}