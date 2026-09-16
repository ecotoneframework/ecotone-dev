<?php

namespace App\Microservices\CustomerService\Infrastructure;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

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
            ->withDocumentStore(enableDocumentStoreStateStoredRepository: true,);
    }

    #[ServiceContext]
    public function distributedPublisher()
    {
        return [
            DistributedServiceMap::initialize()
                ->withCommandMapping("backoffice_service", "backoffice_service"),
            AmqpBackedMessageChannelBuilder::create("backoffice_service")
        ];
    }
}
