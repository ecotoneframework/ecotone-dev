<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Modelling\Api\Distribution\DistributedServiceMap;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function retryConfiguration(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            'errorChannel',
            RetryTemplateBuilder::fixedBackOff(100)
                ->maxRetryAttempts(3),
            'dbal_dead_letter'
        );
    }

    #[ServiceContext]
    public function aggregateRepository(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
                ->withDocumentStore(enableDocumentStoreStandardRepository: true,);
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