<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
final class RecordedHint
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly bool $critical,
    ) {
    }
}
