<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\AppendStrategy;

use Ecotone\Api\EventSourcing\AppendCondition;

/**
 * licence Enterprise
 */
final class EnterpriseAppendStrategy implements AppendStrategy
{
    public function __construct(
        private readonly AppendStrategy $openCoreAppendStrategy,
    ) {
    }

    public function append(AppendableStore $store, string $streamName, array $events, ?AppendCondition $appendCondition): void
    {
        $hasTagCondition = $appendCondition !== null && $appendCondition->hasTagCondition();

        if (! $hasTagCondition && ! $store->anyEventCarriesTag($events)) {
            $this->openCoreAppendStrategy->append($store, $streamName, $events, $appendCondition);

            return;
        }

        $store->appendEventsWithTagCondition($streamName, $events, $appendCondition);
    }
}
