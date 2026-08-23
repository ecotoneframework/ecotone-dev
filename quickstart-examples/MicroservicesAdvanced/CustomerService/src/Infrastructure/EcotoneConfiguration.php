<?php

namespace App\Microservices\CustomerService\Infrastructure;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Modelling\Api\Distribution\DistributedServiceMap;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
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
