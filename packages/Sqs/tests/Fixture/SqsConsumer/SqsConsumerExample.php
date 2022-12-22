<?php

declare(strict_types=1);

namespace Test\Ecotone\Fixture\SqsConsumer;

use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Modelling\Attribute\QueryHandler;

final class SqsConsumerExample
{
    private array $collectedMessages = [];

    #[MessageConsumer("sqs_consumer")]
    public function collect(string $payload): void
    {
        $this->collectedMessages[] = $payload;
    }

    #[QueryHandler("get_collected_messages")]
    public function getCollectedMessages(): array
    {
        return $this->collectedMessages;
    }
}