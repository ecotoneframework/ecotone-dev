<?php

namespace App\OutboxPattern\Infrastructure;

use App\OutboxPattern\Application\EventHandlerService;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class Configuration
{
    const DATABASE_CHANNEL = "asynchronous_messages";
    const EXTERNAL_BROKER_CHANNEL = "asynchronous_external_broker";

    #[ServiceContext]
    public function enableRabbitMQ()
    {
        return DbalBackedMessageChannelBuilder::create(self::DATABASE_CHANNEL);
    }

    // We may want to use dbal channel to keep outbox, however handle messages via consumer reading from RabbitMQ or SQS for example
    #[ServiceContext]
    public function potentialHighThroughChannel()
    {
        return SimpleMessageChannelBuilder::createQueueChannel(self::EXTERNAL_BROKER_CHANNEL);
    }

    #[ServiceContext]
    public function consumerDefinition()
    {
        return [
            PollingMetadata::create(self::DATABASE_CHANNEL)
                    ->setHandledMessageLimit(2)
                    ->setExecutionTimeLimitInMilliseconds(1000),
            PollingMetadata::create(self::EXTERNAL_BROKER_CHANNEL)
                ->setHandledMessageLimit(1)
                ->setExecutionTimeLimitInMilliseconds(1000)
        ];
    }

    #[ServiceContext]
    public function enableDocumentStoreRepository()
    {
        return DbalConfiguration::createWithDefaults()
                ->withDocumentStore(enableDocumentStoreAggregateRepository: true);
    }
}