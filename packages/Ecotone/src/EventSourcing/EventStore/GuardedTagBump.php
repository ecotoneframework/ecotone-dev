<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore;

use Ecotone\Api\EventSourcing\AppendCondition;

/**
 * licence Enterprise
 */
interface GuardedTagBump
{
    public function bumpTagsGuarded(AppendCondition $appendCondition): void;
}
