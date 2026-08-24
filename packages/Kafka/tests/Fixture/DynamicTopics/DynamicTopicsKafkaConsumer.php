<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\DynamicTopics;

use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\QueryHandler;

/**
 * licence Enterprise
 */
final class DynamicTopicsKafkaConsumer
{
    /**
     * @var string[]
     */
    private array $messages = [];

    #[KafkaConsumer('dynamicTopicsConsumer', topics: "reference('topicsProvider').getTopics()")]
    public function handle(string $payload): void
    {
        $this->messages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('dynamicTopicsConsumer.getMessages')]
    public function getMessages(): array
    {
        return $this->messages;
    }
}
