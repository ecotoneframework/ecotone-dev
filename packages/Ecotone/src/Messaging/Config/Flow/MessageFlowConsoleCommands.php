<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Config\ConfigurationException;

final class MessageFlowConsoleCommands
{
    public function dump(ConfiguredMessagingSystem $messagingSystem, ConsoleWriter $writer, MessageFlowFrameworkGraphBuilder $frameworkGraph, MessageFlowFrameworkLedger $frameworkLedger, #[ConsoleParameterOption] string $format = 'mermaid', bool $framework = false): void
    {
        $framework
            ? self::write(graph: $frameworkGraph->build(), writer: $writer, format: $format, framework: $frameworkLedger->toArray())
            : self::write(graph: $messagingSystem->getMessageFlowGraph(), writer: $writer, format: $format, framework: null);
    }

    /**
     * @param array<string, mixed>|null $framework
     */
    public static function write(MessageFlowGraph $graph, ConsoleWriter $writer, string $format, ?array $framework): void
    {
        $writer->write(match ($format) {
            'mermaid' => $graph->toMermaid(),
            'json' => ($framework !== null ? json_encode([...$graph->toArray(), 'framework' => $framework], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $graph->toJson()) . "\n",
            default => throw ConfigurationException::create("ecotone:flow:dump was asked for format `{$format}`, which it cannot produce. Pass --format=mermaid for a Mermaid flowchart to paste into a diagram viewer, or --format=json for the graph as nodes and edges."),
        });
    }
}
