<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class UnavailableTraceCompletion
{
    public function isCompleted(string $traceId): bool
    {
        throw self::refusal();
    }

    public function isCompletedFor(string $traceId, #[Header(StoredTraceCompletion::DESTINATION_HEADER)] string $destination): bool
    {
        throw self::refusal();
    }

    public function await(string $traceId, #[Header(StoredTraceCompletion::TIMEOUT_HEADER)] Duration $timeout): void
    {
        throw self::refusal();
    }

    public function awaitFor(string $traceId, #[Header(StoredTraceCompletion::DESTINATION_HEADER)] string $destination, #[Header(StoredTraceCompletion::TIMEOUT_HEADER)] Duration $timeout): void
    {
        throw self::refusal();
    }

    private static function refusal(): ConfigurationException
    {
        return ConfigurationException::create('TraceCompletion needs stored traces, which decide when a trace is complete. Register TracingConfiguration::createWithDefaults()->withStoredTraces() from a #[ServiceContext] method.');
    }
}
