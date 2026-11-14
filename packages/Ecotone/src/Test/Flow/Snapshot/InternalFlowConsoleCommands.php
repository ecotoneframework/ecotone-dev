<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Messaging\Config\Flow\InternalMessageFlowGraphBuilder;

/**
 * licence Apache-2.0
 */
final class InternalFlowConsoleCommands
{
    #[ConsoleCommand('ecotone:internal-flow:record', 'Framework development: stores the internal wiring of this application and the steps traced so far as the snapshot of the named scenario')]
    public function record(ConsoleWriter $writer, InternalMessageFlowGraphBuilder $internalMessageFlow, InMemorySpanRecorder $spanRecorder, #[ConsoleParameterOption] string $scenario, #[ConsoleParameterOption] string $location): void
    {
        $snapshot = FlowSnapshot::of($scenario, $internalMessageFlow->build(), $spanRecorder->recordedSpans());
        (new FlowSnapshots($location))->store($snapshot);

        $writer->success("Recorded scenario {$scenario} in {$location}/{$snapshot->fileName()}");
    }
}
