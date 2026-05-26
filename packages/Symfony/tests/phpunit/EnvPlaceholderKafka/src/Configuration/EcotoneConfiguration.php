<?php

declare(strict_types=1);

namespace Symfony\App\EnvPlaceholderKafka\Configuration;

use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Enterprise
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function kafkaPublisher(): KafkaPublisherConfiguration
    {
        return KafkaPublisherConfiguration::createWithDefaults('orders.topic.' . getenv('ECOTONE_KAFKA_SUFFIX'))
            ->withHeaderMapper('*');
    }
}
