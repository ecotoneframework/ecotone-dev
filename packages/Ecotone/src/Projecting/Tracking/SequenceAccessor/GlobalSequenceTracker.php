<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Tracking\SequenceAccessor;

use Ecotone\Modelling\Event;
use Ecotone\Projecting\Tracking\SequenceTracker;

class GlobalSequenceTracker implements SequenceTracker
{
    private GapAwarePosition $gapAwarePosition;
    public function __construct(?string $lastPosition)
    {
        $this->gapAwarePosition = GapAwarePosition::fromString($lastPosition);
    }

    public function add(Event $event): void
    {
        $position = (int) $event->getMetadata()['_position'] ?? throw new \RuntimeException('Event does not have a position');

        $this->gapAwarePosition->advanceTo($position);
    }

    public function toPosition(): string
    {
        return (string) $this->gapAwarePosition;
    }
}