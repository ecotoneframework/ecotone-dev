<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Ecotone\Api\Tracing\Span;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class Flow
{
    /**
     * @param string[] $pending
     * @param string[] $reached
     * @param Span[] $steps
     */
    public function __construct(
        public readonly string $tracingId,
        public readonly bool $isCompleted,
        public readonly array $pending,
        public readonly array $reached,
        public readonly array $steps,
    ) {
    }
}
