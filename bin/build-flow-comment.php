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

      php bin/build-flow-comment.php --comparison=<file> --pull-request=<number> --head-sha=<sha> --run-url=<url> --output=<file>

    --comparison is the output of `php bin/build-flow.php compare`. The output file is JSON holding the pull request
    number, the head sha, whether a flow changed, and the comment body, which opens with the marker line
    USAGE . ' ' . COMMENT_MARKER . " that finds the comment again on the next push.\n";

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([A-Za-z-]+)=(.*)$/s', $argument, $option) !== 1) {
        refuse("Argument `{$argument}` is not understood: options are written --name=value.");
    }
    $options[$option[1]] = $option[2];
}
foreach (['comparison', 'pull-request', 'head-sha', 'run-url', 'output'] as $required) {
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
if (preg_match('#^https://[^\s<>()\[\]]+$#', $options['run-url']) !== 1) {
    refuse("The run url `{$options['run-url']}` is not an https url, so the comment cannot point to the full comparison. Pass the url of this workflow run.");
}
$comparison = @file_get_contents($options['comparison']);
if ($comparison === false) {
    refuse("The comparison file {$options['comparison']} cannot be read. Write it with `php bin/build-flow.php compare > <file>` first.");
}
if (! str_starts_with(ltrim($comparison), COMPARISON_HEADING)) {
    refuse('The comparison file ' . $options['comparison'] . ' does not open with `' . COMPARISON_HEADING . '`, so it is not the output of `php bin/build-flow.php compare` and the comment would show something else. Its first line: ' . strtok(ltrim($comparison) . "\n", "\n"));
}

[$preamble, $scenarios] = scenariosOf(withoutMentions($comparison));
$changed = $scenarios !== [] || str_contains($preamble, "\n" . BUILD_TREE_HEADING . "\n");
$commit = substr($options['head-sha'], 0, 7);
$body = $changed
    ? commentOf(preamble: withExplanation(preamble: withBoundedBuildTree($preamble, $options['run-url']), commit: $commit, runUrl: $options['run-url']), scenarios: $scenarios, runUrl: $options['run-url'])
    : COMMENT_MARKER . "\n" . COMPARISON_HEADING . "\n\nAs of `{$commit}`, this pull request changes the message path of none of the " . scenariosCompared($preamble) . " catalogue scenarios.\n";

file_put_contents($options['output'], json_encode(['pullRequest' => (int) $options['pull-request'], 'headSha' => $options['head-sha'], 'changed' => $changed, 'body' => $body], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

/**
 * GitHub, and the workflow that posts the comment, count a comment's length in UTF-16 code units.
 */
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

function scenariosCompared(string $preamble): string
{
    return preg_match('/None of (\d+) scenarios/', $preamble, $count) === 1 ? $count[1] : (str_contains($preamble, 'The 1 scenario') ? '1' : 'the');
}

function withExplanation(string $preamble, string $commit, string $runUrl): string
{
    $repository = preg_match('#^(https://[^/]+/[^/]+/[^/]+)/actions#', $runUrl, $match) === 1 ? $match[1] : 'https://github.com/ecotoneframework/ecotone-dev';
    $explanation = "Each scenario of the flow scenario catalogue ran on this pull request's code at `{$commit}` and on the base commit of the pull request; this is what changed between the two. "
        . 'Run `php bin/build-flow.php compare --presentation=build` to see it locally, against the committed baseline. '
        . "The baseline is not regenerated in a pull request: main's CI commits it after the merge. "
        . "The terms below are explained in [Reading the flow comparison]({$repository}/blob/main/.agents/skills/framework-dev-environment/references/workflow.md#reading-the-flow-comparison).\n\n";

    return preg_replace('/\A(\s*' . preg_quote(COMPARISON_HEADING, '/') . "\n\n)/", '$1' . addcslashes($explanation, '\\$'), $preamble, 1) ?? $preamble;
}

function withBoundedBuildTree(string $preamble, string $runUrl): string
{
    $position = strpos($preamble, BUILD_TREE_HEADING . "\n");
    if ($position === false || lengthOf(substr($preamble, $position)) <= BUILD_TREE_LIMIT) {
        return $preamble;
    }
    $lines = preg_match('/^```text\n(.*?)^```$/ms', substr($preamble, $position), $tree) === 1 ? substr_count($tree[1], "\n") : substr_count(substr($preamble, $position), "\n");

    return substr($preamble, 0, $position) . BUILD_TREE_HEADING . "\n\nThe build tree has " . number_format($lines) . ' lines, more than this comment holds beside the scenarios, so it is left out here. It is in the `' . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n\n";
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
        $footer = leftOutNote(count($scenarios) - $position - 1, count($scenarios), $runUrl);
        if ($length + lengthOf($scenario) + lengthOf($footer) <= GITHUB_COMMENT_LIMIT) {
            $shown[] = $scenario;
            $length += lengthOf($scenario);
            continue;
        }
        $head = headWithDiagramOf($scenario, $runUrl);
        if ($head !== null && $length + lengthOf($head) + lengthOf($footer) <= GITHUB_COMMENT_LIMIT) {
            $shown[] = $head;
        }
        break;
    }
    $leftOut = count($scenarios) - count($shown);

    return COMMENT_MARKER . "\n" . $preamble . implode('', $shown) . leftOutNote($leftOut, count($scenarios), $runUrl);
}

/**
 * The scenario up to the end of its message path, before the collapsed wiring and what ran, so a diagram is never
 * the part that is left out and no collapsed section is cut open.
 */
function headWithDiagramOf(string $scenario, string $runUrl): ?string
{
    $ends = array_filter([strpos($scenario, "\n<details><summary>Marked wiring"), strpos($scenario, "\n#### What ran")], fn (int|false $end): bool => $end !== false);
    if ($ends === []) {
        return null;
    }
    $name = preg_match('/\A### (.*)$/m', $scenario, $heading) === 1 ? $heading[1] : 'this scenario';

    return substr($scenario, 0, min($ends)) . "\nThe rest of {$name}, its wiring and what ran, is left out to stay within GitHub's 65,536 character limit for a comment; it is in the `" . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n";
}

function leftOutNote(int $leftOut, int $all, string $runUrl): string
{
    return $leftOut <= 0 ? '' : "\n---\n\n{$leftOut} of {$all} changed scenarios are left out to stay within GitHub's 65,536 character limit for a comment. The full comparison is in the `" . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n";
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

/**
 * Text the pull request's code produced cannot notify anyone: an @ outside code gets a zero-width space after it.
 */
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
