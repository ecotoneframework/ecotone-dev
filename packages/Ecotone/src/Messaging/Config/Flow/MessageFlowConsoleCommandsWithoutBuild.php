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
            throw ConfigurationException::create('ecotone:flow:dump --build shows what Ecotone itself registers (buses, interceptors, routing, channels, consumers) and needs the framework tracing level. Return ' . FrameworkTracing::SWITCH_ON . ' from a #[ServiceContext] with #[Environment([...])] naming your development and test environments (Symfony dev, test; Laravel local, testing). At that level every step is also exported as a span, many more per message, so keep it out of production. Or run ecotone:flow:dump without --build.');
        }

        MessageFlowConsoleCommands::write(graph: $messagingSystem->getMessageFlowGraph(), writer: $writer, format: $format, build: null);
    }
}
