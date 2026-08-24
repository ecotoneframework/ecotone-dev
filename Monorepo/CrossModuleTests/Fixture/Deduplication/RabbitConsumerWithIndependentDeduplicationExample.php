<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Deduplication;

use Ecotone\Api\Amqp\RabbitConsumer;
use Ecotone\Api\Deduplicated;
use Ecotone\Api\Payload;
use Ecotone\Api\QueryHandler;

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
