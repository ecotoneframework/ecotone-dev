<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class UnavailableTracingGateway
{
    public function __construct(private string $refusal)
    {
    }

    public function isCompleted(string $tracingId): bool
    {
        throw $this->refusal();
    }

    public function isCompletedFor(string $tracingId, #[Header(StoredTracingGateway::DESTINATION_HEADER)] string $destination): bool
    {
        throw $this->refusal();
    }

    public function await(string $tracingId, #[Header(StoredTracingGateway::TIMEOUT_HEADER)] Duration $timeout): void
    {
        throw $this->refusal();
    }

    public function awaitFor(string $tracingId, #[Header(StoredTracingGateway::DESTINATION_HEADER)] string $destination, #[Header(StoredTracingGateway::TIMEOUT_HEADER)] Duration $timeout): void
    {
        throw $this->refusal();
    }

    private function refusal(): ConfigurationException
    {
        return ConfigurationException::create($this->refusal);
    }
}
