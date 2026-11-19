<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Api\Tracing\Span;

/**
 * licence Apache-2.0
 */
interface ExecutionRecordingReader
{
    public function pop(): ExecutionRecording;

    /**
     * @return list<Span>
     */
    public function popSpans(): array;
}
