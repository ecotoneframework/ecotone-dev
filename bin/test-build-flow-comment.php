<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

const SCRIPT = __DIR__ . '/build-flow-comment.php';
const MARKER = '<!-- ecotone-build-flow-comparison -->';
const RUN_URL = 'https://github.com/ecotoneframework/ecotone-dev/actions/runs/42';

$cases = [
    'a changed flow is posted whole under the marker and the heading' => function (): ?string {
        $comparison = comparison(scenario('order placed', 'diagram A'), scenario('order shipped', 'diagram B'));
        $result = run($comparison);
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [$comment['changed'] === true, 'changed is true'],
            [$comment['pullRequest'] === 17, 'pull request 17'],
            [$comment['headSha'] === str_repeat('a', 40), 'the head sha'],
            [str_starts_with($comment['body'], MARKER . "\n"), 'the body opens with the marker line'],
            [str_contains($comment['body'], "## Message flow: what changed\n"), 'the heading of the comparison'],
            [str_contains($comment['body'], 'order placed') && str_contains($comment['body'], 'order shipped'), 'both scenarios'],
            [! str_contains($comment['body'], 'left out'), 'nothing left out'],
        ]);
    },
    'no changed flow gives the no-change form, which says so and is not marked changed' => function (): ?string {
        $result = run("## Message flow: what changed\n\nNone of 94 scenarios changed its wiring, its build or its traced spans.\n");
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [$comment['changed'] === false, 'changed is false'],
            [str_starts_with($comment['body'], MARKER . "\n"), 'the body opens with the marker line'],
            [str_contains($comment['body'], 'No message flow changed'), 'says no message flow changed'],
            [str_contains($comment['body'], 'None of 94 scenarios changed'), 'keeps the line of the comparison'],
        ]);
    },
    'a comparison over the comment limit keeps whole scenarios from the top and says how many were left out' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 40; $index++) {
            $scenarios[] = scenario('scenario ' . $index, str_repeat('x', 3_000));
        }
        $result = run(comparison(...$scenarios));
        $comment = read($result['output']);
        preg_match_all('/^### `scenario (\d+)`$/m', $comment['body'], $kept);
        $keptNumbers = array_map('intval', $kept[1]);
        $leftOut = 40 - count($keptNumbers);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [mb_strlen($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . mb_strlen($comment['body'])],
            [$keptNumbers === range(1, count($keptNumbers)) && $keptNumbers !== [], 'whole scenarios kept from the top'],
            [substr_count($comment['body'], str_repeat('x', 3_000)) === count($keptNumbers), 'no scenario cut in the middle'],
            [str_contains($comment['body'], "{$leftOut} of 40 changed scenarios are left out"), 'says how many were left out'],
            [str_contains($comment['body'], RUN_URL) && str_contains($comment['body'], 'build-flow-comparison'), 'points to the artifact of the run'],
        ]);
    },
    'a Mermaid diagram larger than GitHub renders is replaced by a pointer to the artifact' => function (): ?string {
        $result = run(comparison(scenario('large wiring', "```mermaid\nflowchart LR\n" . str_repeat("  a --> b\n", 6_000) . '```'), scenario('small wiring', "```mermaid\nflowchart LR\n  a --> b\n```")));
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [! str_contains($comment['body'], str_repeat("  a --> b\n", 6_000)), 'the large diagram is not posted'],
            [str_contains($comment['body'], 'more than the 50,000 GitHub renders'), 'says why the diagram is left out'],
            [str_contains($comment['body'], "```mermaid\nflowchart LR\n  a --> b\n```"), 'the small diagram stays'],
        ]);
    },
    'over the limit, a scenario is cut after its message path diagram, never inside or before it' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 6; $index++) {
            $scenarios[] = pathScenario('scenario ' . $index, str_repeat('y', 2_000), str_repeat('z', 13_000));
        }
        $result = run(comparison(...$scenarios));
        $comment = read($result['output']);
        preg_match_all('/^### `scenario (\d+)`$/m', $comment['body'], $shown);
        $last = (int) end($shown[1]);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [mb_strlen($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . mb_strlen($comment['body'])],
            [substr_count($comment['body'], "```mermaid\n") === count($shown[1]), 'every shown scenario shows its diagram'],
            [substr_count($comment['body'], str_repeat('y', 2_000)) === count($shown[1]), 'no diagram cut in the middle'],
            [substr_count($comment['body'], str_repeat('z', 13_000)) === count($shown[1]) - 1, 'only the text after the diagram of the last shown scenario is left out'],
            [str_contains($comment['body'], "The rest of `scenario {$last}`, its wiring and what ran, is left out"), 'says what of the last scenario is left out'],
            [str_contains($comment['body'], (6 - $last) . ' of 6 changed scenarios are left out'), 'says how many scenarios were left out entirely'],
        ]);
    },
    'the build tree opens the comment and is not taken for a scenario' => function (): ?string {
        $result = run("## Message flow: what changed\n\nNo scenario of 94 changes its message path. 2 change only their build.\n\n### Build: what changes\n\n```text\nMessagingSystem\n└── + module\n```\n");
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [$comment['changed'] === true, 'a build change is a change'],
            [str_contains($comment['body'], "### Build: what changes\n"), 'the build tree is shown'],
            [! str_contains($comment['body'], 'left out'), 'nothing left out'],
        ]);
    },
    'a pull request number that is not a positive integer is refused' => function (): ?string {
        $result = run(comparison(scenario('order placed', 'diagram')), pullRequest: '17; rm -rf /');

        return expectAll([
            [$result['exit'] === 1, 'exit 1, got ' . $result['exit']],
            [str_contains($result['stderr'], 'pull request number'), 'names the pull request number: ' . $result['stderr']],
        ]);
    },
    'a head sha that is not a commit sha is refused' => function (): ?string {
        $result = run(comparison(scenario('order placed', 'diagram')), headSha: 'main');

        return expectAll([
            [$result['exit'] === 1, 'exit 1, got ' . $result['exit']],
            [str_contains($result['stderr'], 'head sha'), 'names the head sha: ' . $result['stderr']],
        ]);
    },
    'a comparison that is not the build-flow comparison is refused, so broken tooling fails the job' => function (): ?string {
        $result = run('PHP Fatal error: something broke');

        return expectAll([
            [$result['exit'] === 1, 'exit 1, got ' . $result['exit']],
            [str_contains($result['stderr'], 'Message flow: what changed'), 'names the heading it expected: ' . $result['stderr']],
        ]);
    },
];

$failures = 0;
foreach ($cases as $name => $case) {
    $failure = $case();
    if ($failure !== null) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}\n  {$failure}\n\n");
        continue;
    }
    echo "ok   {$name}\n";
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of " . count($cases) . " comment cases failed.\n");
    exit(1);
}
echo count($cases) . " comment cases passed.\n";

function scenario(string $name, string $content): string
{
    return "### `{$name}`\n\nNow: it changed.\n\n#### Wiring\n\n{$content}\n";
}

function pathScenario(string $name, string $diagram, string $whatRan): string
{
    return "### `{$name}`\n\nNow: it changed.\n\n#### The message's path\n\n```mermaid\nsequenceDiagram\n{$diagram}\n```\n\n<details><summary>Marked wiring and traced spans</summary>\n\n#### Wiring\n\nUnchanged.\n\n</details>\n\n#### What ran, process by process\n\n{$whatRan}\n";
}

function comparison(string ...$scenarios): string
{
    return "## Message flow: what changed\n\n" . count($scenarios) . " of 94 scenarios change their message path.\n\n" . implode("\n", $scenarios);
}

/**
 * @return array{exit: int, stderr: string, output: string}
 */
function run(string $comparison, string $pullRequest = '17', string $headSha = ''): array
{
    $directory = sys_get_temp_dir() . '/build-flow-comment-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/comparison.md', $comparison);
    $command = sprintf(
        'php %s --comparison=%s --pull-request=%s --head-sha=%s --run-url=%s --output=%s',
        escapeshellarg(SCRIPT),
        escapeshellarg($directory . '/comparison.md'),
        escapeshellarg($pullRequest),
        escapeshellarg($headSha === '' ? str_repeat('a', 40) : $headSha),
        escapeshellarg(RUN_URL),
        escapeshellarg($directory . '/comment.json'),
    );
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $exit = proc_close($process);
    $output = is_file($directory . '/comment.json') ? (string) file_get_contents($directory . '/comment.json') : '';
    exec('rm -rf ' . escapeshellarg($directory));

    return ['exit' => $exit, 'stderr' => $stderr . $stdout, 'output' => $output];
}

/**
 * @return array{pullRequest: int, headSha: string, changed: bool, body: string}
 */
function read(string $output): array
{
    $comment = json_decode($output, true);

    return is_array($comment) ? $comment : ['pullRequest' => 0, 'headSha' => '', 'changed' => null, 'body' => ''];
}

/**
 * @param list<array{bool, string}> $expectations
 */
function expectAll(array $expectations): ?string
{
    foreach ($expectations as [$met, $description]) {
        if (! $met) {
            return 'expected ' . $description;
        }
    }

    return null;
}
