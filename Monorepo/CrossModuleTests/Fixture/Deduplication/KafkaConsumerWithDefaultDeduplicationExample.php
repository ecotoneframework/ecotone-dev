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
final class KafkaConsumerWithDefaultDeduplicationExample
{
    /** @var string[] */
    private array $processedMessages = [];

    #[KafkaConsumer('kafka_default_deduplication_consumer', 'default_deduplication_topic')]
    #[Deduplicated]
    public function handleWithDefaultDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('kafka.getDefaultProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
