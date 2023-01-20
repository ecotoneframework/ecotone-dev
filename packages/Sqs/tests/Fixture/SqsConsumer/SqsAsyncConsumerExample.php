<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Fixture\SqsConsumer;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Asynchronous('sqs')]
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
