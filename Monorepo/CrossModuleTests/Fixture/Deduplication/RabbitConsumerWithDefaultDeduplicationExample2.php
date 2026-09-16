<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Deduplication;

use Ecotone\Api\Amqp\RabbitConsumer;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;

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
