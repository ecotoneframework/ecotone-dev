<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\StreamBasedSource;
use Ecotone\Messaging\Config\ConfigurationException;

#[Attribute]
abstract class Projection extends StreamBasedSource
{
    public const RUNNING_MODE_POLLING = 'polling';
    public const RUNNING_MODE_EVENT_DRIVEN = 'event-driven';
    public const RUNNING_MODE_EVENT_STREAMING = 'event-streaming';

    protected string $runningMode;

    public function __construct(
        public readonly string  $name,
        public readonly ?string $partitionHeaderName = null,
        public readonly bool    $automaticInitialization = true,
        string $runningMode = self::RUNNING_MODE_EVENT_DRIVEN,
        public readonly ?string $endpointId = null,
        public readonly ?string $streamingChannelName = null,
    ) {
        if ($partitionHeaderName !== null && $runningMode === self::RUNNING_MODE_POLLING) {
            throw ConfigurationException::create(
                "Partition header name is not supported for polling projections. " .
                "Partitioning is only available for event-driven and event-streaming modes."
            );
        }
        $this->runningMode = $runningMode;
    }

    public function isPolling(): bool
    {
        return $this->runningMode === self::RUNNING_MODE_POLLING;
    }

    public function isEventDriven(): bool
    {
        return $this->runningMode === self::RUNNING_MODE_EVENT_DRIVEN;
    }

    public function isEventStreaming(): bool
    {
        return $this->runningMode === self::RUNNING_MODE_EVENT_STREAMING;
    }

    public function getEndpointId(): ?string
    {
        return $this->endpointId;
    }

    public function getStreamingChannelName(): ?string
    {
        return $this->streamingChannelName;
    }
}
