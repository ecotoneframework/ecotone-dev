<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Deduplication;

use Ecotone\Amqp\Api\Attribute\RabbitConsumer;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class RabbitConsumerWithIndependentDeduplicationExample
{
    /** @var string[] */
    private array $processedMessages = [];

    #[RabbitConsumer('rabbit_independent_deduplication_consumer', 'deduplication_queue_independent')]
    #[Deduplicated('customOrderId')]
    public function handleWithCustomDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('rabbit.getIndependentProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
