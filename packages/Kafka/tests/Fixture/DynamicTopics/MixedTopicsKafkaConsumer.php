<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\DynamicTopics;

use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\QueryHandler;

/**
 * licence Enterprise
 */
final class MixedTopicsKafkaConsumer
{
    /**
     * @var string[]
     */
    private array $messages = [];

    #[KafkaConsumer('mixedTopicsConsumer', topics: ['literalOrdersTopic', "reference('topicsProvider').getTopics()"])]
    public function handle(string $payload): void
    {
        $this->messages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('mixedTopicsConsumer.getMessages')]
    public function getMessages(): array
    {
        return $this->messages;
    }
}
