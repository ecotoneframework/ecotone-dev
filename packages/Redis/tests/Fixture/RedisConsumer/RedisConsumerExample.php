<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Fixture\RedisConsumer;

use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class RedisConsumerExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[MessageConsumer('redis_consumer')]
    public function collect(string $payload): void
    {
        $this->messagePayloads[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('consumer.getMessagePayloads')]
    public function getMessagePayloads(): array
    {
        return $this->messagePayloads;
    }
}
