---
name: framework-testing
description: >-
  How tests are written in the Ecotone framework repository: test-first with
  EcotoneLite::bootstrapFlowTesting(), userland-only assertions (no SQL on Ecotone tables,
  no reflection, no internal classes), every path a guarantee must hold on, inline anonymous
  fixtures, snake_case names, pinned time, channels reached only through handlers, and a flow scenario
  for every new flow. Use when writing, changing or reviewing a test, or fixing a bug or adding a feature that
  needs one, in the ecotone-dev repository.
---

# Writing framework tests

[references/rules.md](references/rules.md) has rules 10, 10a and 11 in full with a worked example;
[references/testing-guidelines.md](references/testing-guidelines.md) has the detail on storage, channels, time and
fixtures.

## Applies when

- Adding a feature or fixing a bug (the test comes first)
- Writing, moving or reviewing any test under `packages/*/tests`
- Adding or changing a message flow, or a feature that takes part in one: an attribute, a channel type, an
  interceptor point, a module, an endpoint kind, a routing or delivery behaviour

## Rules

### T1. Test first, one increment per commit
RED, then GREEN, then refactor. Prove the test is red against the base commit before making it pass. A pure
refactor rides on the tests that already cover it.
- **Expected:** the plan writes the failing test before the implementation and runs it red first.
- **Verified by:** `eval:write-feature-test`

### 10. Tests validate at the userland level only
Every test boots with `EcotoneLite::bootstrapFlowTesting()` and drives buses, gateways, attributes,
`#[ServiceContext]` configuration and console commands. Never construct, call or name a class outside `Api/`, never
run SQL or schema calls against Ecotone's tables, never use reflection or statement counting. A failure is produced
through a seam the application owns: the connection it hands Ecotone, an `#[Around]` interceptor, a handler that
throws. The test is right when every class under `src/` could be rewritten and it would stay unchanged.
- **Expected:** the test uses only `Ecotone\Api\...` types plus its own fixtures, asserts through a gateway or
  query handler, and contains no SQL against `ecotone_*` tables, no `ReflectionClass`, no `src/` class.
- **Verified by:** `eval:write-feature-test`

### 10a. A guarantee is proved on every path that has to hold it
Direct call, class-routed bus send, routed send, gateway, console command, and the reconstruction/backfill path
beside the live one — one test per path.
- **Expected:** a guarantee reachable through several entry points gets a test per entry point.
- **Verified by:** `eval:write-feature-test`

### 10b. Tests run messages through Ecotone's own testing support
`EcotoneLite`, `run()`, `FlowTestSupport`, the pinned clock, and several applications booted in one process for a
cross-service flow. Never `pcntl_fork`, `proc_open`/`Process`, a socket or pipe handshake, or a
`microtime()`/`time()`/`usleep()` loop; when the support falls short, add it to the framework in its own commit.
Real concurrency (processes racing a database constraint or lock) is the one exception, named in the test.
- **Expected:** a test needing a consumer or a second service boots it with `EcotoneLite` and drives it with
  `run()` (waiting with `whenWaiting()` on the pinned clock); no fork, process, socket or wall-clock loop appears.
- **Verified by:** `eval:cross-service-test`

### 11. Test shape: snake_case, no comments, inline anonymous fixtures
Method names describe the behaviour in `snake_case`; no comments, docblocks or assertion messages. Fixtures are
inline anonymous classes registered via `$fixture::class`; a named class below the `TestCase` only where PHP forces
one (a type declaration, an attribute argument, a DBAL event-sourced aggregate without `#[AggregateType]`, syntax
newer than PHP 8.2). Never a shared `Fixture/` directory.
- **Expected:** `public function test_<behaviour>(): void`, anonymous-class handlers, commands/events as named
  classes below the test case, no comments.
- **Verified by:** `eval:write-feature-test`

### T2. A channel is reached through an `#[Asynchronous]` handler
Send the command or event, call `run('channel')`, assert what the handler observed. A delayed message: `run()`
before `advanceTimeBy()` handles nothing, `run()` after it does.
- **Expected:** no direct channel send/receive in the test; `run('<channel>')` drives consumption.
- **Verified by:** `eval:write-feature-test`

### T3. Time is pinned at bootstrap
`bootstrapFlowTesting(initialTime: ...)` (or `withTimeAt()` on a `TestConfiguration` the test passes anyway), read
with `$ecotone->now()`, moved with `advanceTimeBy()`; assert exact timestamps, not tolerances.
- **Expected:** a time-dependent test pins the clock at bootstrap and asserts exact values.
- **Verified by:** `eval:write-feature-test`

### T4. A new or changed flow joins the flow scenario catalogue
When a change adds or changes a message flow, or a feature that takes part in one, and no scenario of the flow
scenario catalogue (`packages/Ecotone/src/Test/Flow`, the scenarios the build-flow baseline and the tracing
conformance tests run) exercises it, the change adds one: a scenario method in the matching `*FlowScenarios`
class, listed in its `all()`, with the spans it must produce. The pull request leaves the baseline alone: the
comparison shows the scenario as `runs only now`, and main's CI commits its baseline after the merge. A feature
that cannot run in the catalogue (it needs OpenSwoole, an external broker, a second process) is said so in the pull
request. [references/flow-scenario-catalogue.md](references/flow-scenario-catalogue.md) shows how to add one.
- **Expected:** for a change that adds a flow or a flow feature no scenario covers, the plan or the diff adds a
  scenario method to a `*FlowScenarios` class, lists it in `all()`, states its expected spans, and leaves
  `packages/Ecotone/tests/BuildFlow/baseline` untouched; or it names why the feature cannot be a scenario.
- **Verified by:** `eval:new-flow-scenario`, `check:flow-catalogue`

## References

- [references/rules.md](references/rules.md) — rules 10, 10a, 11 in full, the worked example, what is wrongly
  believed to force a named fixture. Load when writing a test.
- [references/testing-guidelines.md](references/testing-guidelines.md) — storages, channels, time,
  `StaticMessageBuilder`, flaky tests.
- [references/flow-scenario-catalogue.md](references/flow-scenario-catalogue.md) — how to add a flow scenario, where
  it runs, and what the pull request shows. Load when a change adds or changes a message flow.
- In-memory vs storage parity suites: `framework-storage-and-transactions` rule 9.
