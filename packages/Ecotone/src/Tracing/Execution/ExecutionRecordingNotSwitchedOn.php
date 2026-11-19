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
        throw self::refusal();
    }

    public function popSpans(): array
    {
        throw self::refusal();
    }

    private static function refusal(): ConfigurationException
    {
        return ConfigurationException::create('The execution recording was asked for, but this application does not trace at TracingLevel::Framework, so its execution is not recorded and there is nothing to read. Switch the framework level on with ' . FrameworkTracing::SWITCH_ON . ' in a #[ServiceContext] or in the extension objects of EcotoneLite::bootstrapFlowTesting().');
    }
}
