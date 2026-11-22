# Developer experience review: execution recording

Re-check at head 13753bc8b (range c74807b4e..HEAD, 20 commits; first review at c74807b4e, range origin/main...HEAD).

NOT PASSED: DX9

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

The user needs two imports (`TracingConfiguration`, `TracingLevel`), one configuration object with one option, and one read; no span recorder and no service registered. The second surface is `ecotone:flow:dump --framework` (Enterprise), which needs the same level, returned from a `#[ServiceContext]` limited with `#[Environment]` to dev and test. The maintainer's surface is the pull request comment (`var/771/pr-comment.md`) and `bin/build-flow.php`. The new commits change neither the Api/ surface nor the public skills; only the `framework` rename touches `Api/Messaging/Flow/MessageFlowGraph.php`.

## Decisions

- DX9 — missing — bin/build-flow-comment.php `notShownNote()` (the sentence added in 6dc7ddab5) — the sentence calls every scenario left out a "changed scenario", but the count also holds new scenarios after the first one and gone scenarios. The repository's own test case 'thirty changed scenarios and two new ones…' (bin/test-build-flow-comment.php:174-186) prints "29 changed scenarios are not shown here" when 28 changed scenarios and 1 new one were left out. A maintainer reading on a phone trusts that count and does not learn that a new flow was added and is not shown; a gone flow is hidden the same way. Simpler form: count by kind, e.g. "29 scenarios are not shown here (28 changed, 1 new)", with `gone` as a third kind and the singular when the count is 1. The rest of the sentence (the limit, the artifact, the local command) stays. — skill: framework-public-api
  - The earlier DX9 item is covered: `ecotone:flow:dump --build` is now `--framework` (MessageFlowConsoleCommands.php:18, MessageFlowConsoleCommandsWithoutFramework.php:19), and the rest of the application surface was renamed with it: the help text (MessageFlowModule.php), the refusal "ecotone:flow:dump --framework … Or run ecotone:flow:dump without --framework.", the dump's JSON key `framework`, the `framework` attribute of nodes and edges and `classDef framework` (MessageFlowGraph.php:240-243), and "framework wiring" in MessageFlowBuildNotRead.php. The only `build` left is in the maintainer tooling's snapshot format (BuildFlowLedgerComparisonTest.php:357), which is allowed.
  - Other wording of the new comment, checked and acceptable: the flow-diagram legend ("Green is a hand-over that happens only now, red one that happened only before, plain one that happens both…, dashed … taken from the wiring because no span is recorded") reads without the code. Icons now sit before the code font ("🟢 🚪 `entrypoint sends to …`"), and "· not shown here" in the scenario list is clear.
  - Optional (not blocking): the legend explains coloured arrows only, while new boxes (`GatheringPartCompletion`, `consumer of backfill`) are green too; "green is a step or hand-over that happens only now" would cover both.
- DX1 — covered — unchanged by the new commits: the usage above, each element justified (configuration object, the level enum, the reader on `FlowTestSupport` next to `popRecordedEvents()`). The flow test registers its own span recorder; the code in api/skills/ecotone-testing/SKILL.md is the same.
- DX2 — covered — unchanged: switched on by a configuration object (`TracingConfiguration::withLevel()`) passed as an extension object or returned from `#[ServiceContext]`, the same shape as `withSpanRecorder()`; read with one `FlowTestSupport` method.
- DX3 — covered — unchanged: the level is set once in Core `TracingConfiguration`. A second `TracingConfiguration` is refused with a message naming the fix (FrameworkTracing.php `configurationOf`). `--framework` reuses that level and has no setting of its own.
- DX4 — covered — unchanged: the default is `TracingLevel::Application`. The recording needs only the level. Asking without it is refused and the message names the switch (ExecutionRecordingNotSwitchedOn.php); `--framework` without it is refused the same way.
- DX5 — covered — unchanged: `withLevel()` has two meaningful values; `lines` vs `linesWithEveryHint`, `RecordedHint::shownInLines` and the `SpanAttribute::EXECUTION_*` constants each have a reader. The new comment limits (2 changed, 1 new) are constants of the maintainer tooling, not options.
- DX6 — covered — unchanged: tracing stays at the application level by default; Pulse and flow tracking show the same at both levels; `popExecutionRecording()` pops and clears like `popRecordedEvents()`. The recording is opt-in, justified by the stated 8–10× span cost. The new clock pinning (e899ec445, bf3bc3f57) is in tests only and adds no default.
- DX7 — covered — no new default touches delivery, retries, transactions or data. The comparison workflow still fails red when its tooling breaks. The crash fix d5688d6e3 lists a poll's interceptors instead of failing. The comment limit does not hide a change: every scenario left out is marked in the list and counted, and the whole comparison stays in the artifact (the count's wording is DX9 above).
- DX8 — not-apply — no less safe behaviour is offered: `TracingLevel::Framework` and `--framework` cost more spans or compile work; they give up no message or data safety, and that cost is stated where they are switched on.
- DX10 — covered — `--framework` now uses the word of `TracingLevel::Framework`, which it needs. `TracingLevel::Framework` keeps 1.x's framework level, `pop*` follows `popRecordedEvents()`, and process, step, hint and sub-flow are used the same way across Api/, the skill and the comment. The new diagram's terms (hand-over, wiring, span) are the glossary's.

Verdict: NOT PASS (1 missing: DX9, the not-shown count of the pull request comment)
