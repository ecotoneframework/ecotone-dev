<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class BuildFlowConsoleCommands
{
    private const BUILD_PRESENTATION = 'build';
    private const JSON_PRESENTATION = 'json';

    #[ConsoleCommand('ecotone:build-flow:snapshot', 'Framework development: runs every scenario of the flow scenario catalogue and stores its snapshot in the location, removing snapshots of scenarios the catalogue no longer has')]
    public function snapshot(ConsoleWriter $writer, #[ConsoleParameterOption] string $location): void
    {
        $files = FlowScenarioRecorder::filesOfTheCatalogue();
        (new FlowSnapshots($location))->replaceWith(files: $files, keeping: FlowScenarioRecorder::fileNamesLeftOutOnThisPhp());

        $writer->success('Stored ' . (count($files) - 1) . " flow snapshots and what they share in {$location}");
    }

    #[ConsoleCommand('ecotone:build-flow:check', 'Framework development: fails when a snapshot stored in the location differs from what the flow scenario catalogue produces now')]
    public function check(ConsoleWriter $writer, #[ConsoleParameterOption] string $location): void
    {
        (new FlowSnapshots($location))->refuseWhenMissing();
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
            'no longer produced' => array_values(array_diff((new FlowSnapshots($location))->fileNames(), array_keys($files), FlowScenarioRecorder::fileNamesLeftOutOnThisPhp())),
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

    #[ConsoleCommand('ecotone:build-flow:compare', 'Framework development: prints what changed between the snapshots stored in two locations, cut to the changed parts: --presentation=build (the default) what the pull request comment shows as GitHub Markdown with Mermaid (the framework tree, then per scenario the Before/Now summary, what ran process by process with the step tree of each changed process, the message path, and the marked wiring collapsed), json the raw comparison')]
    public function compare(ConsoleWriter $writer, #[ConsoleParameterOption] string $from, #[ConsoleParameterOption] string $to, #[ConsoleParameterOption] string $presentation = self::BUILD_PRESENTATION): void
    {
        if (! in_array($presentation, [self::BUILD_PRESENTATION, self::JSON_PRESENTATION], true)) {
            throw InvalidArgumentException::create("ecotone:build-flow:compare was asked for presentation `{$presentation}`, which it cannot produce. Pass --presentation=build (the default) for what the pull request comment shows: the framework tree, then per scenario the Before/Now summary, what ran process by process, the message's path and the marked wiring collapsed; or --presentation=json for the raw comparison.");
        }
        $before = new FlowSnapshots($from);
        $after = new FlowSnapshots($to);
        $leftOut = FlowScenarioRecorder::fileNamesLeftOutOnThisPhp();
        $comparison = FlowSnapshotComparison::between(before: $before->allBut($leftOut), after: $after->allBut($leftOut), sharedBefore: $before->shared(), sharedAfter: $after->shared());

        $writer->write($presentation === self::JSON_PRESENTATION ? $comparison->toJson() : FlowComparisonMarkdown::of($comparison));
    }
}
