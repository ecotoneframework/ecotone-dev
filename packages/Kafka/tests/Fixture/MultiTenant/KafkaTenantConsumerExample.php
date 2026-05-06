<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Kafka\Attribute\KafkaConsumer;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class KafkaTenantConsumerExample
{
    /** @var array<int, array<string, mixed>> */
    private array $captured = [];

    /** @param array<string, string|null> $configuredTopics */
    public function __construct(private array $configuredTopics)
    {
    }

    #[KafkaConsumer('tenantTopicConsumer', topics: ['tenant_a_topic', 'tenant_b_topic'])]
    #[WithTenantResolver(expression: "headers['kafka_topic']")]
    public function handle(string $payload, #[Headers] array $headers): void
    {
        $this->captured[] = $headers;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[QueryHandler('consumer.lastCapturedHeaders')]
    public function lastCapturedHeaders(): ?array
    {
        return array_shift($this->captured);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[QueryHandler('consumer.allCapturedHeaders')]
    public function allCapturedHeaders(): array
    {
        return $this->captured;
    }

    /**
     * @return array<string, string|null>
     */
    #[QueryHandler('consumer.configuredTopics')]
    public function configuredTopics(): array
    {
        return $this->configuredTopics;
    }
}
