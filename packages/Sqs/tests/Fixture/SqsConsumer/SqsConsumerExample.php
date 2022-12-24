<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Fixture\SqsConsumer;

use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Modelling\Attribute\QueryHandler;

final class SqsConsumerExample
{
    /** @var string[] */
    private array $messagePayloads = [];

    #[MessageConsumer('sqs_consumer')]
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
