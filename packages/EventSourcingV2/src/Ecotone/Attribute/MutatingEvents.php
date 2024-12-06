<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\Attribute;

use Ecotone\Modelling\Attribute\AggregateEvents;

#[\Attribute()]
class MutatingEvents extends AggregateEvents
{
}