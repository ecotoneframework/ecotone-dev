<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Tracing\Config\FrameworkTracing;

final class MessageFlowConsoleCommandsWithoutBuild
{
    public function dump(ConfiguredMessagingSystem $messagingSystem, ConsoleWriter $writer, #[ConsoleParameterOption] string $format = 'mermaid', bool $build = false): void
    {
        if ($build) {
            throw ConfigurationException::create('ecotone:flow:dump --build shows what Ecotone itself registers for the application (buses, interceptors, routing, channels, consumers) and is available only at the framework tracing level. Return ' . FrameworkTracing::SWITCH_ON . ' from a #[ServiceContext]; at that level Ecotone also records every step it runs for each message: as spans sent to the span recorders registered with TracingConfiguration::withSpanRecorder(), as ecotone.execution.* attributes on the spans an OpenTelemetry tracer exports, and nowhere when neither is configured. Or run ecotone:flow:dump without --build for the message flow the application declares.');
        }

        MessageFlowConsoleCommands::write(graph: $messagingSystem->getMessageFlowGraph(), writer: $writer, format: $format, build: null);
    }
}
