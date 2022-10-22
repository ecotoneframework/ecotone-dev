<?php declare(strict_types=1);

namespace App\Microservices\BackofficeService\Infrastructure;

use Ecotone\Amqp\Distribution\AmqpDistributedBusConfiguration;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function distributedConsumer()
    {
        return [
            AmqpDistributedBusConfiguration::createConsumer(),
            PollingMetadata::create("backoffice_service")
                ->setStopOnError(true)
                ->setExecutionTimeLimitInMilliseconds(1000)
        ];
    }
}