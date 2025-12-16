<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Attribute;

use Attribute;
use Ecotone\Messaging\MessageHeaders;

/**
 * A partitioned projection that processes events based on a partition key.
 * Each partition is processed independently, allowing for parallel processing.
 * Defaults to using the aggregate ID as the partition key.
 * Automatic initialization is always enabled for partitioned projections.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class PartitionedProjection extends Projection
{
    public function __construct(
        string $name,
        string $partitionHeaderName = MessageHeaders::EVENT_AGGREGATE_ID,
        string $runningMode = self::RUNNING_MODE_EVENT_DRIVEN,
        ?string $endpointId = null,
        ?string $streamingChannelName = null,
    ) {
        parent::__construct(
            name: $name,
            partitionHeaderName: $partitionHeaderName,
            automaticInitialization: true,
            runningMode: $runningMode,
            endpointId: $endpointId,
            streamingChannelName: $streamingChannelName,
        );
    }
}

