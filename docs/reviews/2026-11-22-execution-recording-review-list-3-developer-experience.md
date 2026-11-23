# Developer experience review: execution recording

Re-check 2 at head 45f4a66e7 (range 13753bc8b..HEAD, 7 commits). Earlier rounds: c74807b4e (DX9 --build), 13753bc8b (DX9 not-shown count).

PASSED

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

- DX9 — covered — bin/build-flow-comment.php `notShownNote()` (834e5d52e) counts by kind: "29 scenarios are not shown here (28 changed, 1 gone)", "1 scenario is not shown here (1 new)", singular for 1. Each case is asserted in bin/test-build-flow-comment.php:57-250, which I read and did not run. When the size limit cuts scenarios, the sentence says so and names every cut new scenario ("among them the new `x`"). The diagram legend now covers boxes as well as arrows: "a green or red box a step only such hand-overs reach" (FlowComparisonMarkdown.php:656, var/771/pr-comment.md:983). The rename of the classes behind `ecotone:flow:dump --framework` (`MessageFlowFramework*`, `MessageFlowFrameworkLedger`, reader `framework()`) leaves every application-facing string saying framework: the help text, the refusal, the JSON key `framework`, the `framework` attribute and `classDef`, and MessageFlowFrameworkNotRead's message. The tooling's heading is now "### Framework: what changes" and "The framework tree has …". The snapshot key `build` and the tool name `build-flow` are the maintainer's tooling, kept as known-deviations 6a by the coordinator's decision; they are not on the application surface.
- DX1 — covered — unchanged: the usage above; the new commits change no Api/ class and no public skill.
- DX2 — covered — unchanged: the feature is still switched on with `TracingConfiguration::withLevel()` from a `#[ServiceContext]` or an extension object, and read with one `FlowTestSupport` method.
- DX3 — covered — unchanged: the level is set once; `--framework` reuses it.
- DX4 — covered — unchanged: the default is `TracingLevel::Application`; reading the recording or running `--framework` without the level is refused, and the message names the switch. 0a7202aa1 makes a `#[StreamSource]` with its own parameter names load at the framework level, so the level needs no extra configuration from the user there.
- DX5 — covered — unchanged: no new option. The comment limit (2 changed, every new one) is a constant of the maintainer tooling.
- DX6 — covered — unchanged: the neighbouring defaults are as decided at 13753bc8b.
- DX7 — covered — no new default touches delivery, retries, transactions or data. The comment now shows every new scenario; when GitHub's size limit cuts any, the sentence counts them and names the cut new ones, so no change is hidden, and the whole comparison stays in the artifact.
- DX8 — not-apply — no less safe behaviour is offered (the reason is unchanged).
- DX10 — covered — `--framework` and the renamed classes and tooling headings use the one word of `TracingLevel::Framework`. The two `build` names left in the snapshot format and the tool name are recorded in known-deviations 6a.

Verdict: PASS (0 missing)
