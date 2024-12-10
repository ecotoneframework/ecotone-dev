<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Projection;

interface FlushableProjector extends Projector
{
    public function flush(): void;
}