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

[$preamble, $scenarios] = scenariosOf($comparison);
$changed = $scenarios !== [];
$body = $changed
    ? commentOf($preamble, $scenarios, $options['run-url'])
    : COMMENT_MARKER . "\n" . COMPARISON_HEADING . "\n\nNo message flow changed in the latest push of this pull request. " . trim(substr(trim($preamble), strlen(COMPARISON_HEADING))) . "\n";

file_put_contents($options['output'], json_encode(['pullRequest' => (int) $options['pull-request'], 'headSha' => $options['head-sha'], 'changed' => $changed, 'body' => $body], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

/**
 * @return array{0: string, 1: list<string>}
 */
function scenariosOf(string $comparison): array
{
    $parts = preg_split('/^(?=### )/m', $comparison);
    $preamble = (string) array_shift($parts);

    return [$preamble, array_values($parts)];
}

/**
 * @param list<string> $scenarios
 */
function commentOf(string $preamble, array $scenarios, string $runUrl): string
{
    $scenarios = array_map(fn (string $scenario): string => withRenderableDiagrams($scenario, $runUrl), $scenarios);
    $kept = [];
    $length = mb_strlen(COMMENT_MARKER . "\n" . $preamble);
    foreach ($scenarios as $scenario) {
        $footer = leftOutNote(count($scenarios) - count($kept) - 1, count($scenarios), $runUrl);
        if ($length + mb_strlen($scenario) + mb_strlen($footer) > GITHUB_COMMENT_LIMIT) {
            break;
        }
        $kept[] = $scenario;
        $length += mb_strlen($scenario);
    }
    $leftOut = count($scenarios) - count($kept);

    return COMMENT_MARKER . "\n" . $preamble . implode('', $kept) . ($leftOut === 0 ? '' : leftOutNote($leftOut, count($scenarios), $runUrl));
}

function leftOutNote(int $leftOut, int $all, string $runUrl): string
{
    return $leftOut <= 0 ? '' : "\n---\n\n{$leftOut} of {$all} changed scenarios are left out to stay within GitHub's 65,536 character limit for a comment. The full comparison is in the `" . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).\n";
}

function withRenderableDiagrams(string $scenario, string $runUrl): string
{
    return (string) preg_replace_callback('/^```mermaid\n(.*?)^```$/ms', function (array $diagram) use ($runUrl): string {
        $size = mb_strlen($diagram[1]);
        if ($size <= GITHUB_MERMAID_LIMIT) {
            return $diagram[0];
        }

        return '> This diagram has ' . number_format($size) . ' characters, more than the 50,000 GitHub renders, so it is left out here. It is in the `' . COMPARISON_ARTIFACT . "` artifact of [this run]({$runUrl}).";
    }, $scenario);
}

function refuse(string $message): never
{
    fwrite(STDERR, $message . "\n\n" . USAGE);
    exit(1);
}
