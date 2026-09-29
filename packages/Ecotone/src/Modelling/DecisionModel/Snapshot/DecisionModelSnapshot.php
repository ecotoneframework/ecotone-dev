<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel\Snapshot;

/**
 * licence Enterprise
 */
final class DecisionModelSnapshot
{
    public function __construct(
        public readonly object $state,
        public readonly int $coveredPosition,
    ) {
    }
}
