<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Tracking;

use Ecotone\Modelling\Event;

interface SequenceTracker
{
    public function add(Event $event): void;
    public function toPosition(): string;
}