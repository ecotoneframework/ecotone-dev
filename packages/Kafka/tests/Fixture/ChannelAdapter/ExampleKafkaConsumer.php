<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\ChannelAdapter;

use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Kafka\KafkaConsumer;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
