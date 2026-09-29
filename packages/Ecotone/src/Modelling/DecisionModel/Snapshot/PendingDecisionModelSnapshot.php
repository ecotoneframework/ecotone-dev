<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel\Snapshot;

/**
 * licence Enterprise
 */
final class PendingDecisionModelSnapshot
{
    /**
     * @param class-string $modelClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $scopeKey,
        public readonly string $envelopeJson,
    ) {
    }
}
