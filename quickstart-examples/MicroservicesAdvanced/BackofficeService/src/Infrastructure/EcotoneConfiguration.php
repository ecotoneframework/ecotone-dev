<?php declare(strict_types=1);

namespace App\Microservices\BackofficeService\Infrastructure;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function distributedConsumer()
    {
        return [
            AmqpBackedMessageChannelBuilder::create("backoffice_service"),
            PollingMetadata::create("backoffice_service")
                ->setStopOnError(true)
                ->setExecutionTimeLimitInMilliseconds(1000)
        ];
    }
}