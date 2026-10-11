<?php

declare(strict_types=1);

namespace Symfony\App\EnvPlaceholderKafka;

use Ecotone\Api\Attribute\Payload;
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
final class PlaceOrderKafkaConsumer
{
    /** @var string[] */
    private array $consumedPayloads = [];

    #[KafkaConsumer('ordersKafkaConsumer', 'orders.topic.%env(ECOTONE_KAFKA_SUFFIX)%')]
    public function handle(#[Payload] string $payload): void
    {
        $this->consumedPayloads[] = $payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('ordersKafka.consumedPayloads')]
    public function consumedPayloads(): array
    {
        return $this->consumedPayloads;
    }
}
