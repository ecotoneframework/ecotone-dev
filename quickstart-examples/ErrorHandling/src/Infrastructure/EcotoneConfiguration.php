<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function retryConfiguration(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            'errorChannel',
            RetryTemplateBuilder::fixedBackOff(100)
                ->maxRetries(3),
            'dbal_dead_letter'
        );
    }

    #[ServiceContext]
    public function aggregateRepository(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
                ->withDocumentStore(enableDocumentStoreStateStoredRepository: true,);
    }

    #[ServiceContext]
    public function distributed(): array
    {
        return [
            DistributedServiceMap::initialize()
                ->withCommandMapping('example_service', 'example_service'),
            AmqpBackedMessageChannelBuilder::create('example_service'),
        ];
    }

    #[ServiceContext]
    public function asynchronousMessageChannel(): AmqpBackedMessageChannelBuilder
    {
        return AmqpBackedMessageChannelBuilder::create('orders');
    }
}