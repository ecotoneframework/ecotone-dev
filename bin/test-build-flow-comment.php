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
        for ($index = 1; $index <= 2; $index++) {
            $scenarios[] = scenario('scenario ' . $index, str_repeat('x', 40_000));
        }
        $result = run(comparison(...$scenarios));
        $comment = read($result['output']);
        preg_match_all('/^### `scenario (\d+)`$/m', $comment['body'], $kept);
        $keptNumbers = array_map('intval', $kept[1]);
        $leftOut = 2 - count($keptNumbers);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [mb_strlen($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . mb_strlen($comment['body'])],
            [$keptNumbers === range(1, count($keptNumbers)) && $keptNumbers !== [], 'whole scenarios kept from the top'],
            [substr_count($comment['body'], str_repeat('x', 40_000)) === count($keptNumbers), 'no scenario cut in the middle'],
            [$leftOut === 1 && str_contains($comment['body'], '1 of 2 changed scenarios is left out'), 'says how many were left out'],
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
    'over the limit, whole scenarios are cut from the end and the scenarios shown stay whole' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 2; $index++) {
            $scenarios[] = pathScenario('scenario ' . $index, str_repeat('y', 100), processBlocks(5, str_repeat('p', 8_000)));
        }
        $result = run(comparison(...$scenarios));
        $comment = read($result['output']);
        preg_match_all('/^### `scenario (\d+)`$/m', $comment['body'], $shown);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . utf16Length($comment['body'])],
            [count($shown[1]) > 0 && substr_count($comment['body'], str_repeat('p', 8_000)) === 5 * count($shown[1]), 'every shown scenario keeps all its processes'],
            [! str_contains($comment['body'], 'The rest of') && ! str_contains($comment['body'], "in the run's artifact"), 'no shown scenario is cut or emptied'],
            [str_contains($comment['body'], (2 - count($shown[1])) . ' of 2 changed scenarios is left out'), 'says how many scenarios were left out'],
            [structureProblemOf($comment['body']) === null, 'balanced, with blank lines around blocks: ' . structureProblemOf($comment['body'])],
        ]);
    },
    'one scenario left out is said in the singular and marked in the list of changed scenarios' => function (): ?string {
        $list = "- `first`: 1 of 1 process changed\n- `second`: 1 of 1 process changed\n\n";
        $comparison = "## Message flow: what changed\n\n2 of 94 scenarios change their message path.\n\n" . $list . implode("\n", [scenario('first', str_repeat('x', 40_000)), scenario('second', str_repeat('z', 40_000))]);
        $comment = read(run($comparison)['output']);

        return expectAll([
            [str_contains($comment['body'], '1 of 2 changed scenarios is left out'), 'singular: ' . substr($comment['body'], -400)],
            [str_contains($comment['body'], "- `first`: 1 of 1 process changed\n- `second`: 1 of 1 process changed · left out of this comment\n"), 'the left-out scenario is marked in the list'],
        ]);
    },
    'a scenario alone over the limit empties the folds of its unchanged processes first and says so' => function (): ?string {
        $unchanged = str_repeat("\n\n" . processBlock(number: 2, of: 31, tree: str_repeat('u', 2_500), changed: false), 30);
        $result = run(comparison(pathScenario('large', str_repeat('y', 100), processBlock(number: 1, of: 31, tree: 'kept change', changed: true) . $unchanged)));
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . utf16Length($comment['body'])],
            [! str_contains($comment['body'], str_repeat('u', 2_500)), 'the unchanged processes are emptied'],
            [substr_count($comment['body'], "<details>\n<summary>Steps of process 2, in the run's artifact</summary>") === 30, 'their folds stay, closed, saying where the steps are'],
            [str_contains($comment['body'], 'kept change') && str_contains($comment['body'], "```mermaid\n"), 'the changed process and the message path stay'],
            [str_contains($comment['body'], "The contents of 30 unchanged processes of `large` are left out to stay within GitHub's 65,536 character limit"), 'says what was left out: ' . substr($comment['body'], -600)],
            [structureProblemOf($comment['body']) === null, 'balanced, with blank lines around blocks: ' . structureProblemOf($comment['body'])],
        ]);
    },
    'a scenario still over the limit then empties its folds of unchanged steps, keeping their summary' => function (): ?string {
        $runs = str_repeat("\n\n<details>\n<summary>… 12 unchanged steps</summary>\n\n<blockquote>\n\n```diff\n  " . str_repeat('r', 2_500) . "\n```\n\n</blockquote>\n\n</details>", 30);
        $result = run(comparison(pathScenario('large', str_repeat('y', 100), processBlock(number: 1, of: 2, tree: 'kept change', changed: true, folds: $runs) . "\n\n" . processBlock(number: 2, of: 2, tree: str_repeat('u', 100), changed: false))));
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . utf16Length($comment['body'])],
            [! str_contains($comment['body'], str_repeat('r', 2_500)), 'the unchanged steps are emptied'],
            [substr_count($comment['body'], "<details>\n<summary>… 12 unchanged steps, in the run's artifact</summary>") === 30, 'their folds keep their summary'],
            [str_contains($comment['body'], 'kept change'), 'the changed steps stay'],
            [str_contains($comment['body'], 'The contents of 1 unchanged process and 30 folds of unchanged steps of `large` are left out'), 'says what was left out: ' . substr($comment['body'], -600)],
            [structureProblemOf($comment['body']) === null, 'balanced, with blank lines around blocks: ' . structureProblemOf($comment['body'])],
        ]);
    },
    'a scenario too large even emptied is cut between whole parts, never inside a fold' => function (): ?string {
        $result = run(comparison(pathScenario('large', str_repeat("  a -->|\"step\"| b\n", 2_000), processBlocks(5, str_repeat('p', 9_000)))));
        $comment = read($result['output']);

        return expectAll([
            [utf16Length($comment['body']) <= 65_536, 'at most 65,536 characters, got ' . utf16Length($comment['body'])],
            [str_contains($comment['body'], 'The rest of `large`, from its message path on, is left out') || str_contains($comment['body'], 'The rest of `large`, from process'), 'says where it is cut'],
            [substr_count($comment['body'], str_repeat('p', 9_000)) === substr_count($comment['body'], '<details open>'), 'no tree cut in the middle'],
            [structureProblemOf($comment['body']) === null, 'balanced, with blank lines around blocks: ' . structureProblemOf($comment['body'])],
        ]);
    },
    'no changed scenario prints no scenario and says nothing is not shown' => function (): ?string {
        $comment = read(run("## Message flow: what changed\n\nNo scenario of 94 changes its message path.\n")['output']);

        return expectAll([
            [! str_contains($comment['body'], 'not shown'), 'nothing is said not shown: ' . $comment['body']],
        ]);
    },
    'one changed scenario is shown whole and nothing is not shown' => function (): ?string {
        $comment = read(run(selectionComparison([changedScenario('first', 3)]))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['first'], 'shown: ' . implode(', ', shownNames($comment['body']))],
            [! str_contains($comment['body'], 'not shown'), 'nothing is said not shown'],
        ]);
    },
    'two changed scenarios are both shown' => function (): ?string {
        $comment = read(run(selectionComparison([changedScenario('first', 1), changedScenario('second', 2)]))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['first', 'second'], 'shown: ' . implode(', ', shownNames($comment['body']))],
            [! str_contains($comment['body'], 'not shown'), 'nothing is said not shown'],
        ]);
    },
    'of three changed scenarios the two with the most changed steps are shown and the third is marked not shown in the singular' => function (): ?string {
        $comment = read(run(selectionComparison([changedScenario('alpha', 1), changedScenario('beta', 5), changedScenario('gamma', 3)]))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['beta', 'gamma'], 'shown in comparison order: ' . implode(', ', shownNames($comment['body']))],
            [str_contains($comment['body'], "- `alpha`: 1 of 2 processes changed · not shown here\n"), 'alpha marked in the list: ' . substr($comment['body'], 0, 900)],
            [str_contains($comment['body'], '1 changed scenario is not shown here'), 'singular sentence'],
            [str_contains($comment['body'], 'php bin/build-flow.php compare --presentation=build') && str_contains($comment['body'], RUN_URL), 'names the artifact and the local command'],
        ]);
    },
    'thirty changed scenarios and two new ones show the two most changed and the first new one, and say the rest are not shown in the plural' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 30; $index++) {
            $scenarios[] = changedScenario(sprintf('changed %02d', $index), $index === 7 ? 9 : ($index === 21 ? 9 : 1));
        }
        $scenarios[] = newScenario('new first');
        $scenarios[] = newScenario('new second');
        $comment = read(run(selectionComparison($scenarios))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['changed 07', 'changed 21', 'new first'], 'shown: ' . implode(', ', shownNames($comment['body']))],
            [str_contains($comment['body'], '29 changed scenarios are not shown here'), 'plural sentence: ' . substr($comment['body'], -700)],
            [substr_count($comment['body'], ' · not shown here') === 29, 'each not shown one is marked in the list'],
        ]);
    },
    'a scenario changing only its wiring ranks after one with changed steps' => function (): ?string {
        $comment = read(run(selectionComparison([wiringOnlyScenario('alpha'), changedScenario('beta', 1), changedScenario('gamma', 1)]))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['beta', 'gamma'], 'shown: ' . implode(', ', shownNames($comment['body']))],
        ]);
    },
    'two changed scenarios with the same number of changed steps keep the order the comparison prints them in' => function (): ?string {
        $comment = read(run(selectionComparison([changedScenario('alpha', 2), changedScenario('beta', 2), changedScenario('gamma', 2)]))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['alpha', 'beta'], 'shown: ' . implode(', ', shownNames($comment['body']))],
        ]);
    },
    'a scenario that is gone is not shown but marked in the list' => function (): ?string {
        $comment = read(run(selectionComparison([changedScenario('alpha', 2), goneScenario('retired')]))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['alpha'], 'shown: ' . implode(', ', shownNames($comment['body']))],
            [str_contains($comment['body'], "- `retired`: 1 of 1 process changed · not shown here\n"), 'the gone one is marked'],
            [str_contains($comment['body'], '1 changed scenario is not shown here'), 'singular sentence'],
        ]);
    },
    'a group of scenarios with the same change that is not shown counts each of its scenarios' => function (): ?string {
        $group = ['name' => 'grouped one', 'kind' => 'changed', 'text' => "### 2 scenarios with the same change\n\nApplies to: `grouped one`, `grouped two`. Shown for `one`; the same holds with `two` in its place.\n\nBefore: it was sent.\n\nNow: it is also audited.\n"];
        $identical = ['name' => 'same one', 'kind' => 'changed', 'text' => "### 2 scenarios with the same change\n\nApplies to: `same one`, `same two`.\n\nBefore: it was sent.\n\nNow: it is also audited.\n"];
        $comparison = str_replace(
            ["- `grouped one`: 1 of 2 processes changed\n", "- `same one`: 1 of 2 processes changed\n"],
            ["- `grouped one`: 1 of 2 processes changed\n- `grouped two`: 1 of 2 processes changed\n- `one`: 1 of 2 processes changed\n", "- `same one`: 1 of 2 processes changed\n- `same two`: 1 of 2 processes changed\n"],
            selectionComparison([changedScenario('alpha', 3), changedScenario('beta', 2), $group, $identical]),
        );
        $comment = read(run($comparison)['output']);

        return expectAll([
            [str_contains($comment['body'], '4 changed scenarios are not shown here'), 'counts every scenario of both groups: ' . substr($comment['body'], -500)],
            [substr_count($comment['body'], ' · not shown here') === 4, 'marks them, and not a scenario a group only names'],
        ]);
    },
    'only new scenarios show the first one' => function (): ?string {
        $comment = read(run(selectionComparison([newScenario('new first'), newScenario('new second')]))['output']);

        return expectAll([
            [shownNames($comment['body']) === ['new first'], 'shown: ' . implode(', ', shownNames($comment['body']))],
            [str_contains($comment['body'], '1 changed scenario is not shown here'), 'singular sentence'],
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
            [str_contains($comment['body'], 'ran on this pull request at `aaaaaaa`, merged into its base, and on the base commit `bbbbbbb`'), 'names the head, the merge and the base commit: ' . substr($comment['body'], 0, 400)],
            [str_contains($comment['body'], '`docker compose exec -T app php bin/build-flow.php compare --presentation=build`'), 'gives the local command in the container form'],
            [str_contains($comment['body'], 'it compares against the committed baseline, which lags main until main\'s CI has committed the baseline of the latest merge'), 'says what the local command compares against and that it can lag'],
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
            [! str_contains($comment['body'], 'Leave `packages/Ecotone/tests/BuildFlow/baseline` alone'), 'does not tell to leave alone the baseline the pull request commits'],
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
            [preg_match('/The build tree has [\d,]+ characters, more than the 16,000 this comment gives it beside the scenarios/', $comment['body']) === 1, 'says the tree is left out for its size'],
            [str_contains($comment['body'], '### `order placed`'), 'the scenario is still shown'],
        ]);
    },
    'a message path diagram too large to render does not move the cut inside the collapsed wiring' => function (): ?string {
        $scenarios = [];
        for ($index = 1; $index <= 2; $index++) {
            $scenarios[] = pathScenario('scenario ' . $index, str_repeat("  a -->|\"step\"| b\n", $index === 1 ? 4_000 : 100), str_repeat('z', 30_000), "```mermaid\nflowchart LR\n" . str_repeat("  a --> b\n", 300) . "```\n");
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
        $result = run('', formatChange: 'Flow snapshot file build-flow-before/shared.json was written by format 2, this code reads format 3, so the two cannot be compared: a comparison across a snapshot format change cannot be drawn. Regenerate the snapshots of that directory with `php bin/build-flow.php snapshot --location=/home/runner/work/build-flow-before`.');
        $comment = read($result['output']);

        return expectAll([
            [$result['exit'] === 0, 'exit 0, got ' . $result['exit'] . ': ' . $result['stderr']],
            [$comment['changed'] === true, 'posted as a change'],
            [str_contains($comment['body'], 'As of `aaaaaaa`, the snapshot format differs between the base commit `bbbbbbb` and this pull request, so a comparison across it cannot be drawn.'), 'says the comparison cannot be drawn: ' . $comment['body']],
            [str_contains($comment['body'], 'was written by format 2, this code reads format 3, so the two cannot be compared: a comparison across a snapshot format change cannot be drawn.'), 'quotes what happened'],
            [! str_contains($comment['body'], 'Regenerate') && ! str_contains($comment['body'], '/home/runner'), 'does not quote the instruction for the runner'],
            [str_contains($comment['body'], 'Nothing to do in this pull request.'), 'says the contributor has nothing to do'],
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
    return "### `{$name}`\n\nNow: it changed.\n\n#### What ran, process by process\n\n{$whatRan}\n\n#### The message's path\n\n```mermaid\nflowchart LR\n{$diagram}\n```\n\n<details><summary>Marked wiring and traced spans</summary>\n\n#### Wiring\n\n{$wiring}\n</details>\n";
}

function processBlocks(int $count, string $tree): string
{
    $blocks = [];
    for ($number = 1; $number <= $count; $number++) {
        $blocks[] = processBlock(number: $number, of: $count, tree: $tree, changed: true);
    }

    return implode("\n\n", $blocks);
}

function processBlock(int $number, int $of, string $tree, bool $changed, string $folds = ''): string
{
    return "**Flow · process {$number} of {$of}** · " . ($changed ? '1 new step · 3 → 4 steps' : 'unchanged · 3 steps') . "\n`process {$number}`\n\n<details" . ($changed ? ' open' : '') . ">\n<summary>Steps of process {$number}</summary>\n\n```diff\n" . ($changed ? '+ ' : '  ') . $tree . "\n```{$folds}\n\n</details>";
}

function structureProblemOf(string $markdown): ?string
{
    $lines = explode("\n", $markdown);
    $inFence = false;
    $open = [];
    foreach ($lines as $position => $line) {
        $before = $lines[$position - 1] ?? '';
        $after = $lines[$position + 1] ?? '';
        if (str_starts_with($line, '```')) {
            if (($inFence ? $after : $before) !== '') {
                return "no blank line around the fence on line {$position}";
            }
            $inFence = ! $inFence;
            continue;
        }
        if ($inFence) {
            continue;
        }
        if (preg_match('/^<(details|blockquote)( open)?>(<summary>.*<\/summary>)?$/', $line, $tag) === 1) {
            $open[] = $tag[1];
            if ($before !== '') {
                return "no blank line before line {$position}";
            }
        } elseif (preg_match('/^<\/(details|blockquote)>$/', $line, $tag) === 1) {
            if (array_pop($open) !== $tag[1]) {
                return "line {$position} closes what is not open";
            }
            if ($before !== '' || $after !== '') {
                return "no blank line around line {$position}";
            }
        } elseif (str_starts_with($line, '<summary>') && $after !== '') {
            return "no blank line after the summary on line {$position}";
        }
    }

    return $inFence ? 'a fence is left open' : ($open === [] ? null : 'left open: ' . implode(', ', $open));
}

/**
 * @param list<array{name: string, text: string}> $scenarios
 */
function selectionComparison(array $scenarios): string
{
    $list = implode("\n", array_map(fn (array $scenario): string => '- `' . $scenario['name'] . '`: ' . ($scenario['kind'] === 'new' || $scenario['kind'] === 'gone' ? '1 of 1 process changed' : '1 of 2 processes changed'), $scenarios));

    return "## Message flow: what changed\n\n" . count($scenarios) . " of 94 scenarios change their message path.\n\n" . $list . "\n\n" . implode("\n", array_map(fn (array $scenario): string => $scenario['text'], $scenarios));
}

function changedScenario(string $name, int $newSteps): array
{
    return ['name' => $name, 'kind' => 'changed', 'text' => "### `{$name}`\n\nBefore: it was sent.\n\nNow: it is also audited.\n\n#### What ran, process by process\n\n**Flow · process 1 of 2** · unchanged · 40 steps\n`process zero`\n\n**Sub-flow · process 2 of 2** · sent by process 1 · {$newSteps} new step" . ($newSteps === 1 ? '' : 's') . ', 1 step gone · 10 → ' . (9 + $newSteps) . " steps\n`process one`\n\n<details open>\n<summary>Steps of process 1</summary>\n\n```diff\n+ step\n```\n\n</details>\n\n#### The message's path\n\n```mermaid\nflowchart LR\n  a -->|\"step\"| b\n```\n"];
}

function wiringOnlyScenario(string $name): array
{
    return ['name' => $name, 'kind' => 'changed', 'text' => "### `{$name}`\n\nBefore: it was sent.\n\nNow: it is routed differently.\n\n<details><summary>Marked wiring and traced spans</summary>\n\n#### Wiring\n\nChanged.\n\n</details>\n"];
}

function newScenario(string $name): array
{
    return ['name' => $name, 'kind' => 'new', 'text' => "### `{$name}`\n\nBefore: this scenario was not in the catalogue.\n\nNow: it is, with 3 traced spans.\n\n#### What ran, process by process\n\n**Flow · process 1 of 1** · runs only now · 12 steps\n`process one`\n\n<details open>\n<summary>Steps of process 1</summary>\n\n```diff\n+ step\n```\n\n</details>\n"];
}

function goneScenario(string $name): array
{
    return ['name' => $name, 'kind' => 'gone', 'text' => "### `{$name}`\n\nBefore: this scenario was in the catalogue, with 3 traced spans.\n\nNow: it is not.\n"];
}

/**
 * @return list<string>
 */
function shownNames(string $body): array
{
    preg_match_all('/^### `([^`]+)`$/m', $body, $names);

    return $names[1];
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
