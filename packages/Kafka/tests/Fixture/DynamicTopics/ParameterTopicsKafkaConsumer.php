<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\DynamicTopics;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class ParameterTopicsKafkaConsumer
{
    /**
     * @var string[]
     */
    private array $messages = [];

    #[KafkaConsumer('parameterTopicsConsumer', topics: "parameter('ordersTopicReferenceName')")]
    public function handle(string $payload): void
    {
        $this->messages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('parameterTopicsConsumer.getMessages')]
    public function getMessages(): array
    {
        return $this->messages;
    }
}
