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
            [str_contains($comment['body'], 'changes the message path of none of the 94 catalogue scenarios'), 'says no message path changed'],
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
    'over the limit, a scenario keeps whole processes of what ran from the top and is cut between two of them, never inside a tree' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 6; $index++) {
            $scenarios[] = pathScenario('scenario ' . $index, str_repeat('y', 100), processBlocks(5, str_repeat('p', 2_500)));
        }
        $result = run(comparison(...$scenarios));
        $comment = read($result['output']);
        preg_match_all('/^### `scenario (\d+)`$/m', $comment['body'], $shown);
        $last = (int) end($shown[1]);
        $lastScenario = substr($comment['body'], (int) strpos($comment['body'], "### `scenario {$last}`"));
        preg_match('/The rest of `scenario ' . $last . '`, from process (\d+) of what ran on, is left out/', $lastScenario, $cut);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . utf16Length($comment['body'])],
            [$cut !== [], 'says from which process of the last scenario what ran is left out'],
            [$cut !== [] && substr_count($lastScenario, '<details open>') === (int) $cut[1] - 1, 'the processes before the cut are shown whole'],
            [substr_count($comment['body'], str_repeat('p', 2_500)) === substr_count($comment['body'], '<details open>'), 'no tree cut in the middle'],
            [substr_count($comment['body'], '<details') === substr_count($comment['body'], '</details>'), 'every <details> is closed'],
            [! str_contains($lastScenario, "```mermaid\n"), 'the message path after the cut is left out with it'],
            [str_contains($comment['body'], (6 - $last) . ' of 6 changed scenarios are left out'), 'says how many scenarios were left out entirely'],
        ]);
    },
    'a scenario whose processes all fit is cut before its message path when the diagram does not' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 6; $index++) {
            $scenarios[] = pathScenario('scenario ' . $index, str_repeat("  a->>b: step\n", 900), processBlocks(1, str_repeat('p', 100)));
        }
        $comment = read(run(comparison(...$scenarios))['output']);
        preg_match_all('/^### `scenario (\d+)`$/m', $comment['body'], $shown);
        $last = (int) end($shown[1]);

        return expectAll([
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters'],
            [substr_count($comment['body'], "```mermaid\n") === substr_count($comment['body'], str_repeat("  a->>b: step\n", 900)), 'no diagram cut in the middle'],
            [str_contains($comment['body'], "The rest of `scenario {$last}`, from its message path on, is left out") || str_contains($comment['body'], "The rest of `scenario {$last}`, from its wiring on, is left out"), 'says the message path or the wiring is left out'],
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
    'the comment says what is compared, at which commits, how to reproduce it and where the terms are explained' => function (): ?string {
        $comment = read(run(comparison(scenario('order placed', 'diagram')))['output']);

        return expectAll([
            [str_contains($comment['body'], 'on this pull request merged into its base (head `aaaaaaa`) and on the base commit `bbbbbbb`'), 'names the merge, the head and the base commit: ' . substr($comment['body'], 0, 400)],
            [str_contains($comment['body'], '`docker compose exec -T app php bin/build-flow.php compare --presentation=build`'), 'gives the local command in the container form'],
            [str_contains($comment['body'], 'it compares against the committed baseline'), 'says what the local command compares against'],
            [str_contains($comment['body'], 'Leave `packages/Ecotone/tests/BuildFlow/baseline` alone in this pull request; main\'s CI commits it after the merge.'), 'tells the contributor to leave the baseline alone'],
            [str_contains($comment['body'], 'https://github.com/ecotoneframework/ecotone-dev/blob/' . str_repeat('a', 40) . '/.agents/skills/framework-dev-environment/references/workflow.md#reading-the-flow-comparison'), 'links the glossary at the head sha'],
            [strpos($comment['body'], 'aaaaaaa') < strpos($comment['body'], '### `order placed`'), 'the explanation comes before the scenarios'],
        ]);
    },
    'a base commit without the tooling says the comparison is with the baseline the pull request commits' => function (): ?string {
        $comment = read(run(comparison(scenario('order placed', 'diagram')), comparedWith: 'committed-baseline')['output']);

        return expectAll([
            [str_contains($comment['body'], 'compared with the baseline this pull request commits, because its base `bbbbbbb` predates the tooling'), 'says what was really compared: ' . substr($comment['body'], 0, 400)],
            [! str_contains($comment['body'], 'and on the base commit'), 'does not claim the base commit was run'],
        ]);
    },
    'what to compare with is refused unless it is the base commit or the committed baseline' => function (): ?string {
        $result = run(comparison(scenario('order placed', 'diagram')), comparedWith: 'somewhere');

        return expectAll([
            [$result['exit'] === 1, 'exit 1, got ' . $result['exit']],
            [str_contains($result['stderr'], '--compared-with'), 'names the option: ' . $result['stderr']],
        ]);
    },
    'no changed flow names the commit and the number of catalogue scenarios' => function (): ?string {
        $comment = read(run("## Message flow: what changed\n\nNone of 94 scenarios changed its wiring, its build or its traced spans.\n")['output']);

        return expectAll([
            [str_contains($comment['body'], 'As of `aaaaaaa`, this pull request changes the message path of none of the 94 catalogue scenarios.'), 'the no-change sentence: ' . $comment['body']],
        ]);
    },
    'no changed flow among one scenario, or an unknown number, reads as a sentence' => function (): ?string {
        $one = read(run("## Message flow: what changed\n\nThe 1 scenario did not change its wiring, its build or its traced spans.\n")['output']);
        $unknown = read(run("## Message flow: what changed\n\nNothing to compare.\n")['output']);

        return expectAll([
            [str_contains($one['body'], 'As of `aaaaaaa`, this pull request does not change the message path of the 1 catalogue scenario.'), 'singular: ' . $one['body']],
            [str_contains($unknown['body'], 'As of `aaaaaaa`, this pull request changes the message path of none of the catalogue scenarios.'), 'no count: ' . $unknown['body']],
        ]);
    },
    'a build tree too long for the comment is left out with a note, and the scenarios still follow' => function (): ?string {
        $tree = "### Build: what changes\n\n```text\nMessagingSystem\n" . str_repeat("└── + a registration of a module with a long name\n", 2_000) . "```\n\n";
        $result = run("## Message flow: what changed\n\n1 of 94 scenarios changes its message path.\n\n" . $tree . scenario('order placed', 'diagram'));
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . utf16Length($comment['body'])],
            [str_contains($comment['body'], 'The build tree has 2,001 lines'), 'says the tree is left out'],
            [str_contains($comment['body'], '### `order placed`'), 'the scenario is still shown'],
        ]);
    },
    'a message path diagram too large to render does not move the cut inside the collapsed wiring' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 6; $index++) {
            $scenarios[] = pathScenario('scenario ' . $index, str_repeat("  a->>b: step\n", $index === 4 ? 4_000 : 100), str_repeat('z', 13_000), "```mermaid\nflowchart LR\n" . str_repeat("  a --> b\n", 300) . "```\n");
        }
        $comment = read(run(comparison(...$scenarios))['output']);

        return expectAll([
            [substr_count($comment['body'], '<details>') === substr_count($comment['body'], '</details>'), 'every <details> is closed: ' . substr_count($comment['body'], '<details>') . ' open, ' . substr_count($comment['body'], '</details>') . ' closed'],
            [str_contains($comment['body'], 'changed scenarios are left out') || str_contains($comment['body'], 'is left out to stay within'), 'the left-out note is there'],
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters'],
        ]);
    },
    'the length is counted in UTF-16 code units, as GitHub and the workflow count it' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 30; $index++) {
            $scenarios[] = scenario('scenario ' . $index, str_repeat('😀', 1_500));
        }
        $comment = read(run(comparison(...$scenarios))['output']);

        return expectAll([
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 UTF-16 code units, got ' . utf16Length($comment['body'])],
        ]);
    },
    'a mention outside code does not notify anyone, inside code it stays as written' => function (): ?string {
        $comment = read(run(comparison(scenario('order placed', "ask @maintainer about it\n\n```text\nuser@example.com @inside\n```")))['output']);

        return expectAll([
            [! preg_match('/(?<![\w`])@maintainer/u', $comment['body']), 'the mention is neutralised'],
            [str_contains($comment['body'], "@\u{200B}maintainer"), 'it still reads as @maintainer'],
            [str_contains($comment['body'], 'user@example.com @inside'), 'code is left as written'],
        ]);
    },
    'a comparison across a snapshot format change is posted as one that cannot be drawn' => function (): ?string {
        $result = run('', formatChange: 'Flow snapshot file build-flow-before/shared.json was written by format 2, this code reads format 3, so the two cannot be compared.');
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [$comment['changed'] === true, 'posted as a change'],
            [str_contains($comment['body'], 'As of `aaaaaaa`, the snapshot format differs between the base commit `bbbbbbb` and this pull request, so a comparison across it cannot be drawn.'), 'says the comparison cannot be drawn: ' . $comment['body']],
            [str_contains($comment['body'], 'was written by format 2, this code reads format 3'), 'quotes what happened'],
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

function pathScenario(string $name, string $diagram, string $whatRan, string $wiring = "Unchanged.\n"): string
{
    return "### `{$name}`\n\nNow: it changed.\n\n#### What ran, process by process\n\n{$whatRan}\n\n#### The message's path\n\n```mermaid\nsequenceDiagram\n{$diagram}\n```\n\n<details><summary>Marked wiring and traced spans</summary>\n\n#### Wiring\n\n{$wiring}\n</details>\n";
}

function processBlocks(int $count, string $tree): string
{
    $blocks = [];
    for ($number = 1; $number <= $count; $number++) {
        $blocks[] = "**Flow · process {$number} of {$count}** · 1 new step · 3 → 4 steps\n`process {$number}`\n\n<details open><summary>Steps of process {$number}</summary>\n\n```diff\n+ {$tree}\n```\n\n</details>";
    }

    return implode("\n\n", $blocks);
}

function utf16Length(string $text): int
{
    return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
}

function comparison(string ...$scenarios): string
{
    return "## Message flow: what changed\n\n" . count($scenarios) . " of 94 scenarios change their message path.\n\n" . implode("\n", $scenarios);
}

/**
 * @return array{exit: int, stderr: string, output: string}
 */
function run(string $comparison, string $pullRequest = '17', string $headSha = '', string $comparedWith = 'base-commit', ?string $formatChange = null): array
{
    $directory = sys_get_temp_dir() . '/build-flow-comment-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/comparison.md', $comparison);
    if ($formatChange !== null) {
        file_put_contents($directory . '/compare-error.txt', $formatChange);
    }
    $command = sprintf(
        'php %s --comparison=%s --pull-request=%s --head-sha=%s --base-sha=%s --compared-with=%s --run-url=%s --output=%s',
        escapeshellarg(SCRIPT),
        escapeshellarg($directory . '/comparison.md'),
        escapeshellarg($pullRequest),
        escapeshellarg($headSha === '' ? str_repeat('a', 40) : $headSha),
        escapeshellarg(str_repeat('b', 40)),
        escapeshellarg($comparedWith),
        escapeshellarg(RUN_URL),
        escapeshellarg($directory . '/comment.json'),
    ) . ($formatChange === null ? '' : ' --format-change=' . escapeshellarg($directory . '/compare-error.txt'));
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
