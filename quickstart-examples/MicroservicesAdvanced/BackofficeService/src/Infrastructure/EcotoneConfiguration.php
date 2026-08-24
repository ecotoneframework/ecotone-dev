<?php declare(strict_types=1);

namespace App\Microservices\BackofficeService\Infrastructure;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\PollingMetadata;

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