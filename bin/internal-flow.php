<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\Snapshot\InternalFlowConsoleCommands;
use Ecotone\Test\LicenceTesting;

require dirname(__DIR__) . '/vendor/autoload.php';

const INTERNAL_FLOW_BASELINE = 'packages/Ecotone/tests/InternalFlow/baseline';
const INTERNAL_FLOW_USAGE = <<<'USAGE'
    Framework development: the internal message flow of the flow scenario catalogue.

      php bin/internal-flow.php snapshot [--location=<directory>]
          Runs every catalogue scenario and stores its internal wiring and traced steps,
          one file per scenario. Without --location it regenerates the committed baseline.

      php bin/internal-flow.php check [--location=<directory>]
          Fails when a stored snapshot differs from what the code produces now.

      php bin/internal-flow.php compare [--from=<directory>] [--to=<directory>] [--presentation=A|B|C|json]
          Prints what changed between two sets of snapshots, scoped to the change, as GitHub
          Markdown with Mermaid. Without --from it compares from the committed baseline, without
          --to it compares to what the code produces now. Presentations: A a before/after pair,
          B one marked diagram, C the message's path as a sequence diagram, json the raw comparison.

    The committed baseline is
    USAGE . ' ' . INTERNAL_FLOW_BASELINE . ".\n";

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([A-Za-z]+)=(.*)$/', $argument, $option) !== 1) {
        fwrite(STDERR, "Argument `{$argument}` is not understood: options are written --name=value.\n\n" . INTERNAL_FLOW_USAGE);
        exit(2);
    }
    $options[$option[1]] = $option[2];
}
$allowedOptions = ['snapshot' => ['location'], 'check' => ['location'], 'compare' => ['from', 'to', 'presentation']];
if (! isset($allowedOptions[$command]) || array_diff(array_keys($options), $allowedOptions[$command]) !== []) {
    fwrite(STDERR, INTERNAL_FLOW_USAGE);
    exit(2);
}

$baseline = dirname(__DIR__) . '/' . INTERNAL_FLOW_BASELINE;
$tooling = EcotoneLite::bootstrapFlowTesting(
    [InternalFlowConsoleCommands::class],
    [new InternalFlowConsoleCommands(), new InMemorySpanRecorder()],
    enabledModules: [],
    licenceKey: LicenceTesting::VALID_LICENCE,
);

try {
    if ($command === 'compare' && ! isset($options['to'])) {
        $options['to'] = sys_get_temp_dir() . '/ecotone-internal-flow-current-' . bin2hex(random_bytes(6));
        $tooling->runConsoleCommand('ecotone:internal-flow:snapshot', ['location' => $options['to']]);
        $generated = $options['to'];
    }
    $tooling->runConsoleCommand('ecotone:internal-flow:' . $command, match ($command) {
        'compare' => ['from' => $options['from'] ?? $baseline, 'to' => $options['to'], 'presentation' => $options['presentation'] ?? 'B'],
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
