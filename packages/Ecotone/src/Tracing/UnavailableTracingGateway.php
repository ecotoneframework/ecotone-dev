<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class UnavailableTracingGateway
{
    public function isCompleted(string $tracingId): bool
    {
        throw self::refusal();
    }

    public function isCompletedFor(string $tracingId, #[Header(StoredTracingGateway::DESTINATION_HEADER)] string $destination): bool
    {
        throw self::refusal();
    }

    public function await(string $tracingId, #[Header(StoredTracingGateway::TIMEOUT_HEADER)] Duration $timeout): void
    {
        throw self::refusal();
    }

    public function awaitFor(string $tracingId, #[Header(StoredTracingGateway::DESTINATION_HEADER)] string $destination, #[Header(StoredTracingGateway::TIMEOUT_HEADER)] Duration $timeout): void
    {
        throw self::refusal();
    }

    private static function refusal(): ConfigurationException
    {
        return ConfigurationException::create('TracingGateway needs stored traces, which decide when a trace is complete. Register TracingConfiguration::createWithDefaults()->withStoredTraces() from a #[ServiceContext] method.');
    }
}
