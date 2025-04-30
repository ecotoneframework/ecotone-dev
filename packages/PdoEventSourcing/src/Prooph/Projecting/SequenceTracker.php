<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\Modelling\Event;

interface SequenceTracker
{
    public function add(Event $event): void;
    public function toPosition(): string;
}