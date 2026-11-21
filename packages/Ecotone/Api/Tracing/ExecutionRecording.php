<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Apache-2.0
 */
final class ExecutionRecording
{
    /**
     * @param list<RecordedProcess> $processes
     * @param list<string> $lines
     * @param list<string> $linesWithEveryHint
     */
    public function __construct(
        public readonly array $processes,
        public readonly array $lines,
        public readonly array $linesWithEveryHint,
    ) {
    }
}
