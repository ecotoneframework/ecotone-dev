<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Messaging\Config\Flow\InternalMessageFlowGraphBuilder;
use Ecotone\Messaging\Config\Flow\MessageFlowBuild;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class InternalFlowConsoleCommands
{
    #[ConsoleCommand('ecotone:internal-flow:record', 'Framework development: stores the internal wiring of this application and the steps traced so far as the snapshot of the named scenario')]
    public function record(ConsoleWriter $writer, InternalMessageFlowGraphBuilder $internalMessageFlow, MessageFlowBuild $build, InMemorySpanRecorder $spanRecorder, #[ConsoleParameterOption] string $scenario, #[ConsoleParameterOption] string $location): void
    {
        $snapshot = FlowSnapshot::of($scenario, $internalMessageFlow->build(), $spanRecorder->recordedSpans(), $build->toArray());
        (new FlowSnapshots($location))->store($snapshot);

        $writer->success("Recorded scenario {$scenario} in {$location}/{$snapshot->fileName()}");
    }

    #[ConsoleCommand('ecotone:internal-flow:snapshot', 'Framework development: runs every scenario of the flow scenario catalogue and stores its snapshot in the location, removing snapshots of scenarios the catalogue no longer has')]
    public function snapshot(ConsoleWriter $writer, #[ConsoleParameterOption] string $location): void
    {
        $files = FlowScenarioRecorder::filesOfTheCatalogue();
        (new FlowSnapshots($location))->replaceWith($files);

        $writer->success('Stored ' . (count($files) - 1) . " flow snapshots and what they share in {$location}");
    }

    #[ConsoleCommand('ecotone:internal-flow:check', 'Framework development: fails when a snapshot stored in the location differs from what the flow scenario catalogue produces now')]
    public function check(ConsoleWriter $writer, #[ConsoleParameterOption] string $location): void
    {
        $files = FlowScenarioRecorder::filesOfTheCatalogue();
        $differs = [];
        $missing = [];
        foreach ($files as $fileName => $content) {
            if (! is_file($location . '/' . $fileName)) {
                $missing[] = $fileName;
            } elseif (file_get_contents($location . '/' . $fileName) !== $content) {
                $differs[] = $fileName;
            }
        }
        $problems = array_filter([
            'differs' => $differs,
            'missing' => $missing,
            'no longer produced' => array_values(array_diff((new FlowSnapshots($location))->fileNames(), array_keys($files))),
        ]);
        if ($problems !== []) {
            throw InvalidArgumentException::create(
                "The flow snapshots in {$location} are not what the code produces: "
                . implode('; ', array_map(fn (string $problem, array $fileNames): string => $problem . ': ' . implode(', ', $fileNames), array_keys($problems), $problems))
                . '. If the message flow was meant to change, regenerate the snapshots with `php bin/internal-flow.php snapshot` and commit them; `php bin/internal-flow.php compare` shows what changed. If it was not, the change altered the framework\'s wiring or traced steps by accident.'
            );
        }

        $writer->success('All ' . (count($files) - 1) . " flow snapshots in {$location} match what the code produces");
    }

    #[ConsoleCommand('ecotone:internal-flow:compare', 'Framework development: prints what changed between the snapshots stored in two locations, cut to the changed parts, as GitHub Markdown with Mermaid: --presentation=A a before/after pair, B one marked diagram, C the message path as a sequence diagram, json the raw comparison')]
    public function compare(ConsoleWriter $writer, #[ConsoleParameterOption] string $from, #[ConsoleParameterOption] string $to, #[ConsoleParameterOption] string $presentation = 'json'): void
    {
        $comparison = FlowSnapshotComparison::between((new FlowSnapshots($from))->all(), (new FlowSnapshots($to))->all());

        $writer->write($presentation === 'json' ? $comparison->toJson() : FlowComparisonMarkdown::of($comparison, $presentation));
    }
}
