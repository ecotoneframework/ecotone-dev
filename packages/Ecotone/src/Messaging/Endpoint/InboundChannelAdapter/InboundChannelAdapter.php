<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\InboundChannelAdapter;

use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Ecotone\Messaging\Scheduling\TaskScheduler;
use Ecotone\Messaging\Scheduling\Trigger;

/**
 * Class InboundChannelAdapter
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class InboundChannelAdapter implements ConsumerLifecycle
{
    public function __construct(private TaskScheduler $taskScheduler, private Trigger $trigger, private TaskExecutor $taskExecutor)
    {
    }

    /**
     * @inheritDoc
     */
    public function run(): void
    {
        $this->taskScheduler->schedule($this->taskExecutor, $this->trigger);
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function isRunningInSeparateThread(): bool
    {
        return true;
    }
}
