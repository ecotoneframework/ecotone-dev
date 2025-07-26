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
