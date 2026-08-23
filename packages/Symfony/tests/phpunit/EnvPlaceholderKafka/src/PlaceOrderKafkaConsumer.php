<?php

declare(strict_types=1);

namespace Symfony\App\EnvPlaceholderKafka;

use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Kafka\Api\Attribute\KafkaConsumer;

/**
 * licence Enterprise
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
