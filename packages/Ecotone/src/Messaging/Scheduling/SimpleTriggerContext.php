<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use Ramsey\Uuid\Type\Time;

/**
 * Class SimpleTriggerContext
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class SimpleTriggerContext implements TriggerContext
{
    /**
     * SimpleTriggerContext constructor.
     */
    private function __construct(private ?Timestamp $lastScheduledExecutionTime, private ?Timestamp $lastActualExecutionTime)
    {
    }

    /**
     * @return SimpleTriggerContext
     */
    public static function createEmpty(): self
    {
        return new self(null, null);
    }

    /**
     * @return SimpleTriggerContext
     */
    public static function createWith(?Timestamp $lastScheduledExecutionTime, ?Timestamp $lastActualExecutionTime): self
    {
        return new self($lastScheduledExecutionTime, $lastActualExecutionTime);
    }

    public function withLastScheduledExecutionTime(Timestamp $lastScheduledExecutionTime): self
    {
        $this->lastScheduledExecutionTime = $lastScheduledExecutionTime;

        return new self($lastScheduledExecutionTime, $this->lastActualExecutionTime());
    }

    public function withLastActualExecutionTime(Timestamp $lastActualExecutionTime): self
    {
        $this->lastActualExecutionTime = $lastActualExecutionTime;

        return new self($this->lastScheduledExecutionTime, $lastActualExecutionTime);
    }

    /**
     * @inheritDoc
     */
    public function lastScheduledTime(): ?Timestamp
    {
        return $this->lastScheduledExecutionTime;
    }

    /**
     * @inheritDoc
     */
    public function lastActualExecutionTime(): ?Timestamp
    {
        return $this->lastActualExecutionTime;
    }
}
