<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Subscription;

use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Subscription\SubscriptionQuery;

interface EventLoader
{
    /**
     * @return iterable<PersistedEvent>
     */
    public function query(SubscriptionQuery $query): iterable;
}