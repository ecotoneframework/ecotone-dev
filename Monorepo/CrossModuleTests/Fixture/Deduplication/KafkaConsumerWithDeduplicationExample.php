<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Deduplication;

use Ecotone\Kafka\Api\Attribute\KafkaConsumer;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithDeduplicationExample
{
    /** @var string[] */
    private array $processedMessages = [];

    #[KafkaConsumer('kafka_deduplication_consumer', 'deduplication_topic')]
    #[Deduplicated('customOrderId')]
    public function handleWithCustomDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('kafka.getProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
