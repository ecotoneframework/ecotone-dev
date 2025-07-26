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
final class KafkaConsumerWithExpressionDeduplicationExample
{
    /** @var string[] */
    private array $processedMessages = [];

    #[KafkaConsumer('kafka_expression_deduplication_consumer', 'expression_deduplication_topic')]
    #[Deduplicated(expression: "headers['orderId']")]
    public function handleWithExpressionDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('kafka.getExpressionProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
