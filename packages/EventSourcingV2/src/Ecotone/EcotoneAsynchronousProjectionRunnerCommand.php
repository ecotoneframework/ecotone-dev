<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\EventStore\LogEventId;

class EcotoneAsynchronousProjectionRunnerCommand
{
    public function __construct(
        public readonly string $subscription,
        public readonly ?LogEventId $until = null
    )
    {
    }
}