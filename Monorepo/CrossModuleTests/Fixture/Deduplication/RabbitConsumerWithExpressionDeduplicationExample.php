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
final class RabbitConsumerWithExpressionDeduplicationExample
{
    /** @var string[] */
    private array $processedMessages = [];

    #[RabbitConsumer('rabbit_expression_deduplication_consumer', 'expression_deduplication_queue')]
    #[Deduplicated(expression: "headers['orderId']")]
    public function handleWithExpressionDeduplication(#[Payload] string $payload): void
    {
        $this->processedMessages[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('rabbit.getExpressionProcessedMessages')]
    public function getProcessedMessages(): array
    {
        return $this->processedMessages;
    }
}
