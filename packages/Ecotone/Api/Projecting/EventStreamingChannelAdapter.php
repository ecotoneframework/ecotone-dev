<?php

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
declare(strict_types=1);

namespace Ecotone\Api\Projecting;

/**
 * Configuration for feeding events from event store into a streaming channel.
 * Creates a polling projection that continuously reads events and publishes them to the specified channel.
 *
 * Works with both PDO Event Store and In-Memory Event Store implementations.
 *
 * @example
 * #[ServiceContext]
 * public function eventStoreFeeder(): EventStreamingChannelAdapter
 * {
 *     return EventStreamingChannelAdapter::create(
 *         streamChannelName: 'event_stream',
 *         endpointId: 'event_store_feeder',
 *         fromStream: Ticket::class
 *     );
 * }
 *
 * licence Enterprise
 */
readonly class EventStreamingChannelAdapter
{
    private function __construct(
        public string  $streamChannelName,
        public string  $endpointId,
        public string  $fromStream,
        public ?string $aggregateType = null,
        public int     $batchSize = 100,
        public array   $eventNames = [],
    ) {
    }

    public static function create(
        string $streamChannelName,
        string $endpointId,
        string $fromStream,
        ?string $aggregateType = null,
    ): self {
        return new self($streamChannelName, $endpointId, $fromStream, $aggregateType);
    }

    public function withBatchSize(int $batchSize): self
    {
        return new self(
            $this->streamChannelName,
            $this->endpointId,
            $this->fromStream,
            $this->aggregateType,
            $batchSize,
            $this->eventNames,
        );
    }

    /**
     * Filter events by name using glob patterns (same as distributed bus)
     * @param array<string> $eventNames Glob patterns like ['Ticket.*', 'Order.Created']
     */
    public function withEventNames(array $eventNames): self
    {
        return new self(
            $this->streamChannelName,
            $this->endpointId,
            $this->fromStream,
            $this->aggregateType,
            $this->batchSize,
            $eventNames,
        );
    }

    /**
     * @internal
     */
    public function getProjectionName(): string
    {
        return 'event_streaming_channel_adapter_' . $this->endpointId;
    }
}
