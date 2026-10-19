<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Api\Dbal\Tracing\Flow;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class UnavailableTracingGateway
{
    public function __construct(private string $refusal)
    {
    }

    public function isCompletedFor(string $tracingId): bool
    {
        throw $this->refusal();
    }

    public function isCompletedWithin(string $tracingId): bool
    {
        throw $this->refusal();
    }

    public function fetchFlow(string $tracingId): ?Flow
    {
        throw $this->refusal();
    }

    private function refusal(): ConfigurationException
    {
        return ConfigurationException::create($this->refusal);
    }
}
