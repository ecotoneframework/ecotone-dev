<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Amqp\Configuration\AmqpMessageConsumerConfiguration;
use Ecotone\Amqp\Distribution\AmqpDistributedBusConfiguration;
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
                ->withDocumentStore(enableDocumentStoreAggregateRepository: true);
    }

    #[ServiceContext]
    public function distributed(): array
    {
        return [
            AmqpDistributedBusConfiguration::createConsumer(),
            AmqpDistributedBusConfiguration::createPublisher(),
            AmqpConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(false)->withTransactionOnCommandBus(false)
        ];
    }

    #[ServiceContext]
    public function asynchronousMessageChannel(): AmqpBackedMessageChannelBuilder
    {
        return AmqpBackedMessageChannelBuilder::create('orders');
    }
}