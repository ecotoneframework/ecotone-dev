<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Messaging\Config\Flow\MessageFlowBuildReader;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Tracing\Execution\ExecutionRecordingReader;

/**
 * licence Apache-2.0
 */
final class BuildFlowConsoleCommands
{
    #[ConsoleCommand('ecotone:build-flow:record', 'Framework development: stores the build wiring of this application and the steps traced so far as the snapshot of the named scenario')]
    public function record(ConsoleWriter $writer, MessageFlowBuildReader $buildReader, ExecutionRecordingReader $recordingReader, #[ConsoleParameterOption] string $scenario, #[ConsoleParameterOption] string $location): void
    {
        $snapshot = FlowSnapshot::of(scenario: $scenario, wiring: $buildReader->wiring(), spans: $recordingReader->popSpans(), build: $buildReader->build());
        (new FlowSnapshots($location))->store($snapshot);

        $writer->success("Recorded scenario {$scenario} in {$location}/{$snapshot->fileName()}");
    }

    #[ConsoleCommand('ecotone:build-flow:snapshot', 'Framework development: runs every scenario of the flow scenario catalogue and stores its snapshot in the location, removing snapshots of scenarios the catalogue no longer has')]
    public function snapshot(ConsoleWriter $writer, #[ConsoleParameterOption] string $location): void
    {
        $files = FlowScenarioRecorder::filesOfTheCatalogue();
        (new FlowSnapshots($location))->replaceWith($files);

        $writer->success('Stored ' . (count($files) - 1) . " flow snapshots and what they share in {$location}");
    }

    #[ConsoleCommand('ecotone:build-flow:check', 'Framework development: fails when a snapshot stored in the location differs from what the flow scenario catalogue produces now')]
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
                . '. If the message flow was meant to change, leave the snapshots as they are: the pull request comment shows the change, and main\'s CI commits the new snapshots after the merge; `php bin/build-flow.php compare --presentation=build --from=' . $location . '` shows it locally as the comment does. If it was not meant to change, the change altered the framework\'s wiring or traced spans by accident.'
            );
        }

        $writer->success('All ' . (count($files) - 1) . " flow snapshots in {$location} match what the code produces");
    }

    #[ConsoleCommand('ecotone:build-flow:compare', 'Framework development: prints what changed between the snapshots stored in two locations, cut to the changed parts, as GitHub Markdown with Mermaid: --presentation=before-after (the default) a before/after pair, marked one marked diagram, path the message path as a sequence diagram, build what the pull request comment shows (the build tree, then per scenario the Before/Now summary, what ran process by process with the step tree of each changed process, the message path, and the marked wiring collapsed), json the raw comparison')]
    public function compare(ConsoleWriter $writer, #[ConsoleParameterOption] string $from, #[ConsoleParameterOption] string $to, #[ConsoleParameterOption] string $presentation = FlowComparisonMarkdown::BEFORE_AFTER_PAIR): void
    {
        $before = new FlowSnapshots($from);
        $after = new FlowSnapshots($to);
        $comparison = FlowSnapshotComparison::between($before->all(), $after->all(), $before->shared(), $after->shared());

        $writer->write($presentation === 'json' ? $comparison->toJson() : FlowComparisonMarkdown::of($comparison, $presentation));
    }
}
