<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ConfigurationVariable;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Laravel\LaravelQueueMessageChannelBuilder;

final class MessagingInfrastructure
{
    #[ServiceContext]
    public function databaseConnection(): LaravelConnectionReference
    {
        return LaravelConnectionReference::defaultConnection('pgsql');
    }

    #[ServiceContext]
    public function channels(#[ConfigurationVariable('app.env')] string $environment): array
    {
        if ($environment === 'testing') {
            return [
                SimpleMessageChannelBuilder::createQueueChannel('storefront'),
                SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
                SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
                LaravelQueueMessageChannelBuilder::create('storefront_async', 'database'),
            ];
        }

        return [
            AmqpBackedMessageChannelBuilder::create('storefront'),
            AmqpBackedMessageChannelBuilder::create('ticketing'),
            LaravelQueueMessageChannelBuilder::create('storefront_async', 'database'),
        ];
    }
}
