<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Attribute;

use Attribute;

/**
 * A global projection that processes events without partitioning.
 * All events are processed by a single projection instance.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class GlobalProjection extends Projection
{
    public function __construct(
        string $name,
        bool $automaticInitialization = true,
        string $runningMode = self::RUNNING_MODE_EVENT_DRIVEN,
        ?string $endpointId = null,
        ?string $streamingChannelName = null,
    ) {
        parent::__construct(
            name: $name,
            partitionHeaderName: null,
            automaticInitialization: $automaticInitialization,
            runningMode: $runningMode,
            endpointId: $endpointId,
            streamingChannelName: $streamingChannelName,
        );
    }
}

