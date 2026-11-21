<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

const COMMENT_MARKER = '<!-- ecotone-build-flow-comparison -->';
const COMPARISON_HEADING = '## Message flow: what changed';
const GITHUB_COMMENT_LIMIT = 65_536;
const GITHUB_MERMAID_LIMIT = 50_000;
const COMPARISON_ARTIFACT = 'build-flow-comparison';
const BUILD_TREE_HEADING = '### Build: what changes';
const BUILD_TREE_LIMIT = 16_000;
const USAGE = <<<'USAGE'
    Builds the pull request comment that shows how the message flow changed.

      php bin/build-flow-comment.php --comparison=<file> --pull-request=<number> --head-sha=<sha> --base-sha=<sha> --compared-with=base-commit|committed-baseline --run-url=<url> --output=<file> [--format-change=<file>]

    --comparison is the output of `php bin/build-flow.php compare --presentation=build`. The output file is JSON holding the pull request
    number, the head sha, whether a flow changed, and the comment body, which opens with the marker line
    USAGE . ' ' . COMMENT_MARKER . " that finds the comment again on the next push.\n";

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([A-Za-z-]+)=(.*)$/s', $argument, $option) !== 1) {
        refuse("Argument `{$argument}` is not understood: options are written --name=value.");
    }
    $options[$option[1]] = $option[2];
}
foreach (['comparison', 'pull-request', 'head-sha', 'base-sha', 'compared-with', 'run-url', 'output'] as $required) {
    if (! isset($options[$required])) {
        refuse("--{$required} is missing.");
    }
}
if (preg_match('/^[1-9]\d{0,9}$/', $options['pull-request']) !== 1) {
    refuse("The pull request number `{$options['pull-request']}` is not a positive integer, so the comment cannot name the pull request it belongs to. Pass the number GitHub gives the pull request.");
}
if (preg_match('/^[0-9a-f]{40}$/', $options['head-sha']) !== 1) {
    refuse("The head sha `{$options['head-sha']}` is not a 40 character commit sha, so the comment cannot be matched to the commit it compares. Pass github.event.pull_request.head.sha.");
}
if (preg_match('/^[0-9a-f]{40}$/', $options['base-sha']) !== 1) {
    refuse("The base sha `{$options['base-sha']}` is not a 40 character commit sha, so the comment cannot name the commit it compares with. Pass github.event.pull_request.base.sha.");
}
if (! in_array($options['compared-with'], ['base-commit', 'committed-baseline'], true)) {
    refuse("--compared-with=`{$options['compared-with']}` names neither side the comparison can start from. Pass --compared-with=base-commit when the base commit was snapshotted, or --compared-with=committed-baseline when the base predates the tooling and the pull request's own baseline was compared with.");
}
if (preg_match('#^https://[^\s<>()\[\]]+$#', $options['run-url']) !== 1) {
    refuse("The run url `{$options['run-url']}` is not an https url, so the comment cannot point to the full comparison. Pass the url of this workflow run.");
}
if (isset($options['format-change'])) {
    $formatChange = trim((string) @file_get_contents($options['format-change']));
    $body = COMMENT_MARKER . "\n" . COMPARISON_HEADING . "\n\nAs of `" . substr($options['head-sha'], 0, 7) . '`, the snapshot format differs between the base commit `' . substr($options['base-sha'], 0, 7) . '` and this pull request, so a comparison across it cannot be drawn. '
        . "main's CI regenerates the baseline in the new format after the merge. Nothing to do in this pull request. What the comparison reported:\n\n> " . str_replace("\n", "\n> ", withoutMentions(refusalUpToItsReason($formatChange))) . "\n";
    file_put_contents($options['output'], json_encode(['pullRequest' => (int) $options['pull-request'], 'headSha' => $options['head-sha'], 'changed' => true, 'body' => $body], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
$comparison = @file_get_contents($options['comparison']);
if ($comparison === false) {
    refuse("The comparison file {$options['comparison']} cannot be read. Write it with `php bin/build-flow.php compare --presentation=build > <file>` first.");
}
if (! str_starts_with(ltrim($comparison), COMPARISON_HEADING)) {
    refuse('The comparison file ' . $options['comparison'] . ' does not open with `' . COMPARISON_HEADING . '`, so it is not the output of `php bin/build-flow.php compare` and the comment would show something else. Its first line: ' . strtok(ltrim($comparison) . "\n", "\n"));
}

[$preamble, $scenarios] = scenariosOf(withoutMentions($comparison));
$changed = $scenarios !== [] || str_contains($preamble, "\n" . BUILD_TREE_HEADING . "\n");
$commit = substr($options['head-sha'], 0, 7);
$body = $changed
    ? commentOf(preamble: withExplanation(preamble: withBoundedBuildTree($preamble, $options['run-url']), headSha: $options['head-sha'], baseSha: $options['base-sha'], comparedWith: $options['compared-with'], runUrl: $options['run-url']), scenarios: $scenarios, runUrl: $options['run-url'])
    : COMMENT_MARKER . "\n" . COMPARISON_HEADING . "\n\nAs of `{$commit}`, this pull request " . noChangeOf($preamble) . "\n";

file_put_contents($options['output'], json_encode(['pullRequest' => (int) $options['pull-request'], 'headSha' => $options['head-sha'], 'changed' => $changed, 'body' => $body], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

function lengthOf(string $text): int
{
    return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
}

/**
 * @return array{0: string, 1: list<string>}
 */
function scenariosOf(string $comparison): array
{
    $parts = preg_split('/^(?=### (?!Build: what changes$))/m', $comparison);
    $preamble = (string) array_shift($parts);

    return [$preamble, array_values($parts)];
}

function noChangeOf(string $preamble): string
{
    return match (true) {
        preg_match('/None of (\d+) scenarios/', $preamble, $count) === 1 => "changes the message path of none of the {$count[1]} catalogue scenarios.",
        str_contains($preamble, 'The 1 scenario') => 'does not change the message path of the 1 catalogue scenario.',
        default => 'changes the message path of none of the catalogue scenarios.',
    };
}

function withExplanation(string $preamble, string $headSha, string $baseSha, string $comparedWith, string $runUrl): string
{
    $repository = preg_match('#^(https://[^/]+/[^/]+/[^/]+)/actions#', $runUrl, $match) === 1 ? $match[1] : 'https://github.com/ecotoneframework/ecotone-dev';
    $head = substr($headSha, 0, 7);
    $base = substr($baseSha, 0, 7);
    $explanation = ($comparedWith === 'base-commit'
            ? "Each scenario of the flow scenario catalogue ran on this pull request at `{$head}`, merged into its base, and on the base commit `{$base}`; below is what changed between the two. "
            : "Each scenario of the flow scenario catalogue ran on this pull request at `{$head}`, merged into its base, and was compared with the baseline this pull request commits, because its base `{$base}` predates the tooling. ")
        . 'To see it locally, run `docker compose exec -T app php bin/build-flow.php compare --presentation=build`; it compares against the committed baseline, which lags main until main\'s CI has committed the baseline of the latest merge. '
        . ($comparedWith === 'base-commit' ? "Leave `packages/Ecotone/tests/BuildFlow/baseline` alone in this pull request; main's CI commits it after the merge. " : '')
        . "The terms are explained in [Reading the flow comparison]({$repository}/blob/{$headSha}/.agents/skills/framework-dev-environment/references/workflow.md#reading-the-flow-comparison).\n\n";

    return preg_replace('/\A(\s*' . preg_quote(COMPARISON_HEADING, '/') . "\n\n)/", '$1' . addcslashes($explanation, '\\$'), $preamble, 1) ?? $preamble;
}

function refusalUpToItsReason(string $refusal): string
{
    $end = strpos($refusal, 'cannot be drawn.');

    return $end === false ? $refusal : substr($refusal, 0, $end + strlen('cannot be drawn.'));
}

function withBoundedBuildTree(string $preamble, string $runUrl): string
{
    $position = strpos($preamble, BUILD_TREE_HEADING . "\n");
    if ($position === false || lengthOf(substr($preamble, $position)) <= BUILD_TREE_LIMIT) {
        return $preamble;
    }
    $size = lengthOf(substr($preamble, $position));

    return substr($preamble, 0, $position) . BUILD_TREE_HEADING . "\n\nThe build tree has " . number_format($size) . ' characters, more than the ' . number_format(BUILD_TREE_LIMIT) . ' this comment gives it beside the scenarios, so it is left out here. It is in the `' . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n\n";
}

/**
 * @param list<string> $scenarios
 */
function commentOf(string $preamble, array $scenarios, string $runUrl): string
{
    $scenarios = array_map(fn (string $scenario): string => withRenderableDiagrams($scenario, $runUrl), $scenarios);
    $shown = [];
    $length = lengthOf(COMMENT_MARKER . "\n" . $preamble);
    foreach ($scenarios as $position => $scenario) {
        $footer = leftOutNote(leftOut: count($scenarios) - $position - 1, all: count($scenarios), runUrl: $runUrl);
        if ($length + lengthOf($scenario) + lengthOf($footer) <= GITHUB_COMMENT_LIMIT) {
            $shown[] = $scenario;
            $length += lengthOf($scenario);
            continue;
        }
        if ($shown !== []) {
            break;
        }
        $room = GITHUB_COMMENT_LIMIT - $length - lengthOf($footer);
        $emptied = withUnchangedStepsLeftOut(scenario: $scenario, room: $room, runUrl: $runUrl);
        $head = lengthOf($emptied) <= $room ? $emptied : headWithinOf(scenario: $emptied, room: $room, runUrl: $runUrl);
        if ($head !== null) {
            $shown[] = $head;
        }
        break;
    }
    $leftOut = count($scenarios) - count($shown);
    foreach (array_slice($scenarios, count($shown)) as $scenario) {
        $name = preg_match('/\A### (.*)$/m', $scenario, $heading) === 1 ? $heading[1] : null;
        $preamble = $name === null ? $preamble : (string) preg_replace('/^(- ' . preg_quote($name, '/') . ': .*)$/m', '$1 · left out of this comment', $preamble, 1);
    }

    return COMMENT_MARKER . "\n" . $preamble . implode('', $shown) . leftOutNote(leftOut: $leftOut, all: count($scenarios), runUrl: $runUrl);
}

function withUnchangedStepsLeftOut(string $scenario, int $room, string $runUrl): string
{
    $name = preg_match('/\A### (.*)$/m', $scenario, $heading) === 1 ? $heading[1] : 'this scenario';
    $left = ['processes' => 0, 'unchangedSteps' => 0];
    $emptied = $scenario;
    foreach (['processes' => '/^<details>\n<summary>(Steps of process \d+(?: before)?)<\/summary>$/', 'unchangedSteps' => '/^<details>\n<summary>(… \d+ unchanged steps)<\/summary>$/'] as $folds => $summary) {
        if (lengthOf($emptied . noteOfTheEmptied(name: $name, processes: $left['processes'], unchangedSteps: $left['unchangedSteps'], runUrl: $runUrl)) <= $room) {
            break;
        }
        $emptied = withFoldsEmptied(markdown: $emptied, summary: $summary, count: $left[$folds]);
    }

    return $left === ['processes' => 0, 'unchangedSteps' => 0] ? $scenario : rtrim($emptied, "\n") . "\n" . noteOfTheEmptied(name: $name, processes: $left['processes'], unchangedSteps: $left['unchangedSteps'], runUrl: $runUrl);
}

function withFoldsEmptied(string $markdown, string $summary, int &$count): string
{
    $count = 0;
    $lines = explode("\n", $markdown);
    $kept = [];
    for ($position = 0; $position < count($lines); $position++) {
        if (preg_match($summary, $lines[$position] . "\n" . ($lines[$position + 1] ?? ''), $fold) !== 1) {
            $kept[] = $lines[$position];
            continue;
        }
        $depth = 0;
        $end = $position;
        $inFence = false;
        for (; $end < count($lines); $end++) {
            if (str_starts_with($lines[$end], '```')) {
                $inFence = ! $inFence;
            } elseif (! $inFence && str_starts_with($lines[$end], '<details')) {
                $depth++;
            } elseif (! $inFence && $lines[$end] === '</details>' && --$depth === 0) {
                break;
            }
        }
        array_push($kept, '<details>', '<summary>' . $fold[1] . ", in the run's artifact</summary>", '', '</details>');
        $count++;
        $position = $end;
    }

    return implode("\n", $kept);
}

function noteOfTheEmptied(string $name, int $processes, int $unchangedSteps, string $runUrl): string
{
    $what = implode(' and ', array_filter([
        $processes === 0 ? '' : $processes . ' unchanged process' . ($processes === 1 ? '' : 'es'),
        $unchangedSteps === 0 ? '' : $unchangedSteps . ' fold' . ($unchangedSteps === 1 ? '' : 's') . ' of unchanged steps',
    ]));

    return $what === '' ? '' : "\nThe steps of {$what} of {$name} are left out to stay within GitHub's 65,536 character limit for a comment; they are in the `" . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n";
}

function headWithinOf(string $scenario, int $room, string $runUrl): ?string
{
    $sections = preg_split('/(?=\n\*\*(?:Flow|Sub-flow) · process \d+ of \d+(?: before)?\*\*|\n#### The message\'s path\n|\n<details><summary>Marked wiring)/', $scenario);
    if (! is_array($sections) || count($sections) < 2) {
        return null;
    }
    $name = preg_match('/\A### (.*)$/m', $scenario, $heading) === 1 ? $heading[1] : 'this scenario';
    $kept = '';
    foreach ($sections as $position => $section) {
        $rest = isset($sections[$position + 1]) ? noteOfTheRest(name: $name, section: $sections[$position + 1], runUrl: $runUrl) : '';
        if (lengthOf($kept . $section . $rest) > $room) {
            return $position === 0 || lengthOf($kept . noteOfTheRest(name: $name, section: $section, runUrl: $runUrl)) > $room ? null : $kept . noteOfTheRest(name: $name, section: $section, runUrl: $runUrl);
        }
        $kept .= $section;
    }

    return null;
}

function noteOfTheRest(string $name, string $section, string $runUrl): string
{
    $from = match (true) {
        preg_match('/\A\n\*\*(?:Flow|Sub-flow) · process (\d+) of/', $section, $process) === 1 => 'from process ' . $process[1] . ' of what ran on',
        str_starts_with($section, "\n#### The message's path") => 'from its message path on',
        default => 'from its wiring on',
    };

    return "\n\nThe rest of {$name}, {$from}, is left out to stay within GitHub's 65,536 character limit for a comment; it is in the `" . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n";
}

function leftOutNote(int $leftOut, int $all, string $runUrl): string
{
    return $leftOut <= 0 ? '' : "\n---\n\n{$leftOut} of {$all} changed scenarios " . ($leftOut === 1 ? 'is' : 'are') . " left out to stay within GitHub's 65,536 character limit for a comment. The full comparison is in the `" . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n";
}

function withRenderableDiagrams(string $scenario, string $runUrl): string
{
    return (string) preg_replace_callback('/^```mermaid\n(.*?)^```$/ms', function (array $diagram) use ($runUrl): string {
        $size = lengthOf($diagram[1]);
        if ($size <= GITHUB_MERMAID_LIMIT) {
            return $diagram[0];
        }

        return '> This diagram has ' . number_format($size) . ' characters, more than the 50,000 GitHub renders, so it is left out here. It is in the `' . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).";
    }, $scenario);
}

function withoutMentions(string $markdown): string
{
    $parts = preg_split('/(^```.*?^```$|`[^`\n]*`)/ms', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$markdown];

    return implode('', array_map(fn (string $part): string => str_starts_with($part, '`') ? $part : (string) preg_replace('/(?<![\w.])@(?=[A-Za-z0-9])/', "@\u{200B}", $part), $parts));
}

function refuse(string $message): never
{
    fwrite(STDERR, $message . "\n\n" . USAGE);
    exit(1);
}
