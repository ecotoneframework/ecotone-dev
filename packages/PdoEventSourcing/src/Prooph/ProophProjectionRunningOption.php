<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph;

interface ProophProjectionRunningOption
{
    /** The cache size is how many stream names are cached in memory, the higher the number the less queries are executed and therefore the projection runs faster, but it consumes more memory.  */
    public const OPTION_CACHE_SIZE = 'cache_size';
    /** The sleep options tells the projection to sleep that many microseconds before querying the event store again when no events were found in the last trip. This reduces the number of cpu cycles without the projection doing any real work.  */
    public const OPTION_SLEEP = 'sleep';
    /** The persist block size tells the projector to persist its changes after a given number of operations. This increases the speed of the projection a lot. When you only persist every 1000 events compared to persist on every event, then 999 write operations are saved. The higher the number, the fewer write operations are made to your system, making the projections run faster. On the other side, in case of an error, you need to redo the last operations again. If you are publishing events to the outside world within a projection, you may think of a persist block size of 1 only. */
    public const OPTION_PERSIST_BLOCK_SIZE = 'persist_block_size';
    /** Indicates the time (in milliseconds) the projector is locked. During this time no other projector with the same name can be started. A running projector will update the lock timeout on every loop, except you configure an update lock threshold.  */
    public const OPTION_LOCK_TIMEOUT_MS = 'lock_timeout_ms';
    /** If update lock threshold is set to a value greater than 0 the projection won't update lock timeout until number of milliseconds have passed. Let's say your projection has a sleep interval of 100 ms and a lock timeout of 1000 ms. By default the projector updates lock timeout after each run so basically every 100 ms the lock timeout is set to: now() + 1000 ms This causes a lot of extra work for your database and in case the database is replicated this can cause a lot of network traffic, too.  */
    public const OPTION_UPDATE_LOCK_THRESHOLD = 'update_lock_threshold';
    /** Change load batch size in each run for single projection. This should be set to higher number in case of asynchronously running projection in order to be sure, that it will always catch up.  */
    public const OPTION_LOAD_COUNT = 'load_count';
}