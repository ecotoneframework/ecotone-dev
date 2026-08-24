<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Fixture\RedisConsumer;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;

#[Asynchronous('redis')]
/**
 * licence Apache-2.0
 */
final class RedisAsyncConsumerExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[CommandHandler('redis_consumer', 'redis_async_endpoint')]
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
