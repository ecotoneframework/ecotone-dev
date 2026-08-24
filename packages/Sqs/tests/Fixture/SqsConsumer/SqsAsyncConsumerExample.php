<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Fixture\SqsConsumer;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;

#[Asynchronous('sqs')]
/**
 * licence Apache-2.0
 */
final class SqsAsyncConsumerExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[CommandHandler('sqs_consumer', 'sqs_async_endpoint')]
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
