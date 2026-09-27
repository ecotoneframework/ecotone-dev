<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\AppendStrategy;

use Ecotone\Api\EventSourcing\AppendCondition;

/**
 * licence Enterprise
 */
final class EnterpriseAppendStrategy implements AppendStrategy
{
    public function append(AppendableStore $store, string $streamName, array $events, ?AppendCondition $appendCondition): void
    {
        $store->appendEventsWithTagCondition($streamName, $events, $appendCondition);
    }
}
