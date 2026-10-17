<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConfigurationException;

final class MessageFlowConsoleCommands
{
    #[ConsoleCommand('ecotone:flow:dump', 'Dumps the message flow graph of the application: commands, events, queries, handlers, aggregates, sagas, projections, gateways and channels, with what each handler emits, as Mermaid or JSON')]
    public function dump(ConfiguredMessagingSystem $messagingSystem, ConsoleWriter $writer, #[ConsoleParameterOption] string $format = 'mermaid'): void
    {
        $graph = $messagingSystem->getMessageFlowGraph();

        $writer->write(match ($format) {
            'mermaid' => $graph->toMermaid(),
            'json' => $graph->toJson() . "\n",
            default => throw ConfigurationException::create("ecotone:flow:dump was asked for format `{$format}`, which it cannot produce. Pass --format=mermaid for a Mermaid flowchart to paste into a diagram viewer, or --format=json for the graph as nodes and edges."),
        });
    }
}
