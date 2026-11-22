<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
final class RecordedStep
{
    /**
     * @param list<RecordedHint> $hints
     */
    public function __construct(
        public readonly int $depth,
        public readonly string $label,
        public readonly array $hints,
    ) {
    }
}
