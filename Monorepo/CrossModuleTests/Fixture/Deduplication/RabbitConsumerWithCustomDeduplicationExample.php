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
final class RabbitConsumerWithCustomDeduplicationExample
{
    /** @var string[] */
    private array $processedMessages = [];

    #[RabbitConsumer('rabbit_custom_deduplication_consumer', 'deduplication_queue_custom')]
    #[Deduplicated('customOrderId')]
    public function handleWithCustomDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('rabbit.getCustomProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
