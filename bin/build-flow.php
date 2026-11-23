<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\BuildFlow\BuildFlowConsoleCommands;

require dirname(__DIR__) . '/vendor/autoload.php';

const BUILD_FLOW_BASELINE = 'packages/Ecotone/tests/BuildFlow/baseline';
const BUILD_FLOW_LOCAL_SNAPSHOT = 'var/build-flow-snapshot';
const BUILD_FLOW_USAGE = <<<'USAGE'
    Framework development: the framework view of the message flow of the flow scenario catalogue.

      php bin/build-flow.php snapshot [--location=<directory>]
          Runs every catalogue scenario and stores its framework wiring and traced steps,
          one file per scenario. Without --location it writes to var/build-flow-snapshot, never
          to the committed baseline: main's CI regenerates the baseline after each merge, with
          --location=packages/Ecotone/tests/BuildFlow/baseline.

      php bin/build-flow.php check [--location=<directory>]
          Fails when a stored snapshot differs from what the code produces now.

      php bin/build-flow.php compare [--from=<directory>] [--to=<directory>] [--presentation=build|json]
          Prints what changed between two sets of snapshots, scoped to the change. Without --from
          it compares from the committed baseline, without --to it compares to what the code
          produces now. build (the default) prints what the pull request comment shows, as GitHub
          Markdown with Mermaid: the tree of what changes in what Ecotone registers
          (MessagingSystem > module > endpoint > layers), then per scenario the Before/Now summary,
          what ran process by process with the step tree of each changed process, the message's
          path, and the marked wiring collapsed. json prints the raw comparison.

    The committed baseline is
    USAGE . ' ' . BUILD_FLOW_BASELINE . ", which main's CI commits after a merge; a checkout from before that has none, and\n"
    . "compare and check then refuse, naming how to snapshot a commit of your own to compare with.\n";

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([A-Za-z]+)=(.*)$/', $argument, $option) !== 1) {
        fwrite(STDERR, "Argument `{$argument}` is not understood: options are written --name=value.\n\n" . BUILD_FLOW_USAGE);
        exit(2);
    }
    $options[$option[1]] = $option[2];
}
$allowedOptions = ['snapshot' => ['location'], 'check' => ['location'], 'compare' => ['from', 'to', 'presentation']];
if (! isset($allowedOptions[$command]) || array_diff(array_keys($options), $allowedOptions[$command]) !== []) {
    fwrite(STDERR, BUILD_FLOW_USAGE);
    exit(2);
}

$baseline = dirname(__DIR__) . '/' . BUILD_FLOW_BASELINE;
$tooling = EcotoneLite::bootstrapFlowTesting(
    [BuildFlowConsoleCommands::class],
    [new BuildFlowConsoleCommands(), new InMemorySpanRecorder()],
    enabledModules: [],
    licenceKey: LicenceTesting::VALID_LICENCE,
);

try {
    if ($command === 'compare' && ! isset($options['to'])) {
        $options['to'] = sys_get_temp_dir() . '/ecotone-build-flow-current-' . bin2hex(random_bytes(6));
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $options['to']]);
        $generated = $options['to'];
    }
    $tooling->runConsoleCommand('ecotone:build-flow:' . $command, match ($command) {
        'compare' => ['from' => $options['from'] ?? $baseline, 'to' => $options['to'], 'presentation' => $options['presentation'] ?? 'build'],
        'snapshot' => ['location' => $options['location'] ?? dirname(__DIR__) . '/' . BUILD_FLOW_LOCAL_SNAPSHOT],
        default => ['location' => $options['location'] ?? $baseline],
    });
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($generated)) {
        array_map(unlink(...), glob($generated . '/*.json'));
        rmdir($generated);
    }
}

$written = $tooling->getInMemoryConsoleWriter();
echo $command === 'compare' ? $written->getWrittenContent() : implode("\n", $written->getSuccessLines()) . "\n";
