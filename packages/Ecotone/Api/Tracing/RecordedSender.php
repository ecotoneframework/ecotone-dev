<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
final class RecordedSender
{
    public function __construct(
        public readonly int $processNumber,
        public readonly int $stepNumber,
    ) {
    }
}
