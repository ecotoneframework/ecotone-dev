<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Deduplication;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class KafkaConsumerWithPayloadExpressionDeduplicationExample
{
    /** @var string[] */
    private array $processedMessages = [];

    #[KafkaConsumer('kafka_payload_expression_deduplication_consumer', 'payload_expression_deduplication_topic')]
    #[Deduplicated(expression: 'payload')]
    public function handleWithPayloadExpressionDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('kafka.getPayloadExpressionProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
