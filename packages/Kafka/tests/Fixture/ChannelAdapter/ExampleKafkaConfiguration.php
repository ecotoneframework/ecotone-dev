<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\ChannelAdapter;

use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Enterprise
 */
final class ExampleKafkaConfiguration
{
    #[ServiceContext]
    public function setupPublisher(): KafkaPublisherConfiguration
    {
        return KafkaPublisherConfiguration::createWithDefaults('exampleTopic');
    }
}
