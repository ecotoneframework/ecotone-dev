<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

class ProjectingHeaders
{
    public const PROJECTION_STATE = 'projection.state';
    public const PROJECTION_NAME = 'projection.name';
    public const PROJECTION_EVENT_NAME = 'projection.event_name';
    /**
     * Indicates whether the projection is live and should emit events.
     * When false, events emitted via EventStreamEmitter will be filtered out.
     */
    public const PROJECTION_LIVE = 'projection.live';
    public const MANUAL_INITIALIZATION = 'projection.manual_initialization';
    public const PROJECTION_PARTITION_KEY = 'projection.partitionKey';
    public const PROJECTION_CAN_INITIALIZE = 'projection.canInitialize';
    public const REBUILD_PARTITION_KEY = 'projection.rebuild.partitionKey';
    public const PROJECTION_STREAM_NAME = 'projection.streamName';
    public const PROJECTION_SHOULD_RESET = 'projection.shouldReset';
    public const PROJECTION_REPLAY_STREAM = 'projection.replayStream';
}
