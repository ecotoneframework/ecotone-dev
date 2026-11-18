<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;

/**
 * licence Apache-2.0
 */
interface ExecutionRecordingReader
{
    public function read(): ExecutionRecording;
}
