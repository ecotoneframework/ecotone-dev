<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Projection;

use Ecotone\EventSourcingV2\EventStore\PersistedEvent;

interface Projector
{
    public function project(PersistedEvent $event): void;
}