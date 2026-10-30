<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\Environment;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Api\Symfony\SymfonyMessengerMessageChannelBuilder;

final class MessagingInfrastructure
{
    #[ServiceContext]
    public function databaseConnection(): SymfonyConnectionReference
    {
        return SymfonyConnectionReference::defaultManagerRegistry('default');
    }

    #[ServiceContext]
    #[Environment(['dev', 'prod'])]
    public function channels(): array
    {
        return [
            AmqpBackedMessageChannelBuilder::create('ticketing'),
            AmqpBackedMessageChannelBuilder::create('storefront'),
            SymfonyMessengerMessageChannelBuilder::create('ticketing_async'),
        ];
    }

    #[ServiceContext]
    #[Environment(['test'])]
    public function testChannels(): array
    {
        return [
            SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
            SimpleMessageChannelBuilder::createQueueChannel('storefront'),
            SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
            SymfonyMessengerMessageChannelBuilder::create('ticketing_async'),
        ];
    }
}
