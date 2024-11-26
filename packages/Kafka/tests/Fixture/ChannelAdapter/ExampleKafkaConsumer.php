<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\ChannelAdapter;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class ExampleKafkaConsumer
{
    /**
     * @var array<array{payload: string, metadata: array}>
     */
    private array $messages = [];

    #[KafkaConsumer('exampleConsumer', 'exampleTopic')]
    public function handle(string $payload, array $metadata): void
    {
        $this->messages[] = ['payload' => $payload, 'metadata' => $metadata];
    }

    /**
     * @return array<string>
     */
    #[QueryHandler('getMessages')]
    public function getMessages(): array
    {
        return $this->messages;
    }
}
