<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Tracking\SequenceAccessor;

use Ecotone\Projecting\Tracking\SequenceFactory;
use Ecotone\Projecting\Tracking\SequenceTracker;

class GlobalSequenceFactory implements SequenceFactory
{
    public function create(?string $lastPosition): SequenceTracker
    {
        return new GlobalSequenceTracker($lastPosition);
    }

    public function createPositionFrom(?string $lastPosition, array $events): string
    {
        $sequenceTracker = $this->create($lastPosition);
        foreach ($events as $event) {
            $sequenceTracker->add($event);
        }

        return $sequenceTracker->toPosition();
    }
}