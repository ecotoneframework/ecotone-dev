<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\ChannelAdapter;

use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

final class ExampleKafkaConfiguration
{
    #[ServiceContext]
    public function setupPublisher(): KafkaPublisherConfiguration
    {
        return KafkaPublisherConfiguration::createWithDefaults('exampleTopic');
    }
}
