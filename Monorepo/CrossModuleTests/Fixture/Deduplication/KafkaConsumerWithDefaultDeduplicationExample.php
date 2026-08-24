<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Deduplication;

use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Deduplicated;
use Ecotone\Api\Payload;
use Ecotone\Api\QueryHandler;

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
