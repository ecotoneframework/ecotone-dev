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

final class MessageFlowConsoleCommands
{
    public function dump(ConfiguredMessagingSystem $messagingSystem, ConsoleWriter $writer, InternalMessageFlowGraphBuilder $internalMessageFlow, #[ConsoleParameterOption] string $format = 'mermaid', bool $internal = false): void
    {
        $graph = $internal ? $internalMessageFlow->build() : $messagingSystem->getMessageFlowGraph();

        $writer->write(match ($format) {
            'mermaid' => $graph->toMermaid(),
            'json' => $graph->toJson() . "\n",
            default => throw ConfigurationException::create("ecotone:flow:dump was asked for format `{$format}`, which it cannot produce. Pass --format=mermaid for a Mermaid flowchart to paste into a diagram viewer, or --format=json for the graph as nodes and edges."),
        });
    }
}
