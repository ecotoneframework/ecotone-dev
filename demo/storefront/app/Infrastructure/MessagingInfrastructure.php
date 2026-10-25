<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
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
    public function channels(): array
    {
        return [
            AmqpBackedMessageChannelBuilder::create('storefront'),
            AmqpBackedMessageChannelBuilder::create('ticketing'),
            LaravelQueueMessageChannelBuilder::create('storefront_async', 'database'),
        ];
    }
}
