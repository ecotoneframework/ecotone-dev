<?php

namespace App\Microservices\CustomerService\Infrastructure;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\Distribution\AmqpDistributedBusConfiguration;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class EcotoneConfiguration
{
    const ASYNCHRONOUS_CHANNEL = "asynchronous_channel";

    #[ServiceContext]
    public function asynchronous_messages()
    {
        return [
            DbalBackedMessageChannelBuilder::create(self::ASYNCHRONOUS_CHANNEL),
            PollingMetadata::create(self::ASYNCHRONOUS_CHANNEL)
                ->setStopOnError(true)
                ->setExecutionTimeLimitInMilliseconds(1000)
        ];
    }

    #[ServiceContext]
    public function documentStoreRepository()
    {
        return DbalConfiguration::createWithDefaults()
            ->withDocumentStore(enableDocumentStoreAggregateRepository: true);
    }

    #[ServiceContext]
    public function distributedPublisher()
    {
        return [
            AmqpDistributedBusConfiguration::createPublisher()
        ];
    }
}
