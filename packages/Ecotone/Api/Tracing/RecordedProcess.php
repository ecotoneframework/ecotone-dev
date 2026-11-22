<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
final class RecordedProcess
{
    /**
     * @param list<RecordedStep> $steps
     */
    public function __construct(
        public readonly string $title,
        public readonly array $steps,
        public readonly ?RecordedSender $sentBy,
    ) {
    }
}
