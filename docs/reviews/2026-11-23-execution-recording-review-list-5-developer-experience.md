# Developer experience review: execution recording

Re-check 4 at head cd6f8a2fb (range 71dff8a1d..HEAD, 9 commits: the unneeded-code cleanup U1-U9). Earlier rounds: c74807b4e (DX9 --build), 13753bc8b (DX9 not-shown count), 45f4a66e7 and 26b620eb0 (PASS).

NOT PASSED: DX1

The application-facing surface changed in one place only: the refusal of `popExecutionRecording()` (ExecutionRecordingNotSwitchedOn.php) keeps its wording, and the line about `ecotone:build-flow:record`, which this branch removed and no application could run, is gone. `TracingConfiguration`, `TracingLevel`, `FlowTestSupport::popExecutionRecording()`, `ecotone:flow:dump --framework` and the public skills are unchanged. Everything else in the range is the maintainer's tooling, which is the second end user of this review.

## Usage for the most common case (rule X1)

```php
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;

$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderService::class],
    [new OrderService()],
    [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
);
$ecotone->sendCommand(new PlaceOrder('123'));
$recording = $ecotone->popExecutionRecording();   // processes[] (title, steps[depth, label, hints], sentBy), lines, linesWithEveryHint
```

The user needs two imports (`TracingConfiguration`, `TracingLevel`), one configuration object with one option, and one read; no span recorder and no service registered. The second surface is `ecotone:flow:dump --framework` (Enterprise), which needs the same level, returned from a `#[ServiceContext]` limited with `#[Environment]` to dev and test. The maintainer's surface is the pull request comment and `bin/build-flow.php`. The new commits change neither the Api/ surface nor the public skills; only the `framework` rename touches `Api/Messaging/Flow/MessageFlowGraph.php`.

## Decisions

- DX1 — missing — packages/Ecotone/tests/BuildFlow/FlowSnapshots.php `refuseWhenMissing()` (dd068c2ed), reached through bin/build-flow.php:58 with the default `--from`/`--location` — the most common local case is a branch with no committed baseline (now the state of every checkout until main's CI commits it after a merge). In that case `php bin/build-flow.php compare` and `check` refuse with "write them first with `php bin/build-flow.php snapshot --location=<checkout>/packages/Ecotone/tests/BuildFlow/baseline` on the commit to compare with". That tells the maintainer to write the committed baseline inside the pull request. The comment says to leave the baseline alone, the usage says `snapshot` never writes it by default, and workflow.md says to snapshot into a directory of your own and pass it with `--from`. A maintainer who follows the message gets an untracked baseline that `git add -A` commits into the pull request, and `check` against it then passes trivially. No test covers this wording with the default location (BuildFlowBaselineTest.php:153 and BuildFlowComparisonTest.php:206 use temp directories). Simpler form: when the missing location is the committed baseline, say what workflow.md says, e.g. "The committed baseline packages/Ecotone/tests/BuildFlow/baseline does not exist yet: main's CI commits it after a merge, so merge main to get it. To compare now, snapshot the commit to compare with into a directory of your own (`php bin/build-flow.php snapshot --location=var/build-flow-before` on that commit) and pass it with `compare --from=var/build-flow-before`; do not write the baseline in a pull request." `check` needs the same text without the `--from` part. — skill: framework-error-handling
- DX2 — covered — unchanged: the feature is switched on by `TracingConfiguration::withLevel()` and read with `popExecutionRecording()`; the cleanup removed only a framework-internal seam (MessageFlowFrameworkReader, `popSpans()`), which no application used.
- DX3 — covered — unchanged: the level is set once. `bin/check-build-flow.php` is gone and `php bin/build-flow.php check` is the one way to run the check (workflow.md), so the tooling no longer offers two entry points for one thing.
- DX4 — covered — the application's defaults are unchanged. In the tooling, `compare` defaults to `--presentation=build`, the only Markdown view, which is what the comment shows, and `json` stays for raw output. An unknown presentation, including the removed `before-after`, is refused with a message naming both values (BuildFlowConsoleCommands.php, BuildFlowPresentationTest.php:364).
- DX5 — covered — U3 removed the options nobody used (`before-after`, `marked`, `path` and the diff-style what ran) and U1 removed `--compared-with`. Each remaining option (`--from`, `--to`, `--location`, `--presentation=build|json`) has a use.
- DX6 — covered — unchanged for the application. In the tooling, the default view of `compare` now agrees with the view the PR comment uses.
- DX7 — covered — no default touching delivery, retries, transactions or data changed. When the base commit has no `bin/build-flow.php`, build-flow-comparison.yml stays green, logs "The base commit … has no bin/build-flow.php, so there is no message flow to compare this pull request with and no comment is posted. Merge main into this branch (or rebase it onto main) to get the comparison.", and build-flow-comment.yml then posts nothing and logs why. That is a stated no-op, not a hidden failure, and it can only happen before the tooling is on the base. On main's first run the baseline directory is absent: the baseline workflow snapshots into it, finds the new files through `git ls-files --others` and commits them, so the first run is not taken for "current".
- DX8 — not-apply — no less safe behaviour is offered (unchanged).
- DX9 — covered — names left after the cleanup still read without the code: the compare description and refusal name `build` and `json` and say what each prints, and the bin/build-flow.php usage explains the absent baseline. I searched the tree outside docs/reviews and the .git directory for the removed names (`before-after`, `--presentation=marked|path`, `ecotone:build-flow:record`, `popSpans`, `MessageFlowFrameworkReader`, `check-build-flow`, `--compared-with`, `committed-baseline`). The only hit is the test asserting that `before-after` is refused; the docs/reviews hits are the records of the cleanup itself.
- DX10 — covered — unchanged: the words of the tooling stay as decided (framework, build view, kept as known-deviations 6a).

Outside this section, left to the other sessions: the coverage of deleted tests, the dropped boot-time span in U4, the U9 upgrade reasoning (`TracingConfiguration` constructor, `Tracer` methods), and skill evals are behaviour and breaking-changes questions. GL50 stays missing by the maintainer's decision (guidelines).

Verdict: NOT PASS (1 missing: DX1)
