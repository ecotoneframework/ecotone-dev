<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

class ProjectionExecutor
{
    public function project($event): void
    {
        $this->projectEvent($event);
    }
}