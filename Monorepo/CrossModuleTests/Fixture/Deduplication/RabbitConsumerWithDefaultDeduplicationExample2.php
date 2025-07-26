<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Deduplication;

use Ecotone\Amqp\Attribute\RabbitConsumer;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class RabbitConsumerWithDefaultDeduplicationExample2
{
    /** @var string[] */
    private array $processedMessages = [];

    #[RabbitConsumer('rabbit_default_deduplication_consumer2', 'default_deduplication_queue_default')]
    #[Deduplicated]
    public function handleWithDefaultDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('rabbit.getDefaultProcessedMessages2')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
