<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Config;

use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;

/**
 * licence Apache-2.0
 */
final class FrameworkTracing
{
    public const SWITCH_ON = 'TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withExecutionRecording()';

    public static function recordsExecution(array $extensionObjects): bool
    {
        return ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults())->executionRecording;
    }
}
