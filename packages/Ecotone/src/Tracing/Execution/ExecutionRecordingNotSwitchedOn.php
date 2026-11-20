<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Tracing\Config\FrameworkTracing;

/**
 * licence Apache-2.0
 */
final class ExecutionRecordingNotSwitchedOn implements ExecutionRecordingReader
{
    public function pop(): ExecutionRecording
    {
        throw self::refusal('popExecutionRecording() was called');
    }

    public function popSpans(): array
    {
        throw self::refusal('ecotone:build-flow:record was run');
    }

    private static function refusal(string $askedBy): ConfigurationException
    {
        return ConfigurationException::create($askedBy . ', but this application does not trace at TracingLevel::Framework, so its execution is not recorded and there is nothing to read. The recording is read only in a flow test, EcotoneLite::bootstrapFlowTesting(), at the framework level: return ' . FrameworkTracing::SWITCH_ON . ' from a #[ServiceContext], or pass it in the extension objects of EcotoneLite::bootstrapFlowTesting().');
    }
}
