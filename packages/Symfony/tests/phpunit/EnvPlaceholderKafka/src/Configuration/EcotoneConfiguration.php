<?php

declare(strict_types=1);

namespace Symfony\App\EnvPlaceholderKafka\Configuration;

use Ecotone\Kafka\Api\ExtensionObject\KafkaPublisherConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

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
