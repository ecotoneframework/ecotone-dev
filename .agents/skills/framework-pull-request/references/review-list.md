# The review list

Everything the four pre-PR reviews require of a change. Each review session
([rule P3](../SKILL.md#p3-four-independent-reviews-pass-the-review-list-before-anything-is-pushed)) owns one section and
decides every requirement in it. Nothing is skipped and nothing is left undecided.

## Deciding a requirement

| Status | Means | The report gives |
|---|---|---|
| `not-apply` | the change has nothing this requirement speaks about | the reason, in one line |
| `covered` | the requirement applies and the change meets it | the evidence: the file and line, or the test by name |
| `missing` | the requirement applies and the change does not meet it, or nothing proves that it does | the file and line, what is missing (the scenario that fails, or what the end user hits), what to add, and the skill to load to cover it |

The report opens with the result: `PASSED` when no requirement is `missing`, `NOT PASSED: <ids>` otherwise. Then
one line per requirement, the `missing` ones first because they are the work to do, then the `covered` ones, then
the `not-apply` ones:

```
NOT PASSED: BH4

BH4 — missing — packages/Dbal/tests/Integration/DelayTest.php — runs on PostgreSQL only, the delay column is
      engine-sensitive — run the suite on MySQL and MariaDB and add what fails — skill: framework-testing,
      framework-storage-and-transactions, framework-dev-environment
BH5 — covered — DelayedRetryTest::test_message_is_retried_after_handler_throws
BH7 — not-apply — the change adds no shared state and no storage write
BH15 to BH21 — not-apply — the change has no service state, no wait and no consumer
```

Requirements next to each other that do not apply for one shared reason may share a line, as the last one does. A
`missing` or `covered` requirement always has a line of its own.

A requirement that asks for a proof (a test, an upgrade note, a guard) is `covered` only by that proof, never by
reading the code and finding it plausible. A status is one of the three words: "probably", "partly",
"unverified" and "open" are not statuses, and a requirement that cannot be shown to be met, also because the
session could not read or run what it needed, is `missing`. A requirement that holds once per item (per entry point, per feature,
per default, per name) gets one line per item, with the item named after the id.

Every `missing` line closes with `skill:` and the skill the implementing session loads before covering it, so the
fix is written by the rules it will be judged on. It is the *Skill to cover it with* column of the requirement,
narrowed to what this change needs; in the Guidelines section it is the skill of the rule the requirement cites.

The `missing` lines are the work the implementing session covers before the re-review; in the re-review the session
decides again every requirement that was `missing` and every requirement the new commits touch
([rule P3a](../SKILL.md#p3a-a-fix-is-re-verified-by-resuming-the-review-sub-sessions)). A problem no requirement
speaks about goes under a closing `Not on the list` heading: it does not change the result, and it is a candidate
for a new requirement here (`framework-agent-tooling`).

## Guidelines

Owned by `framework-review-guidelines`. Each requirement is one rule of the project's skills that can be decided on
a change. The last column cites the rule: the skill is the one the implementing session loads to cover the
requirement, and the session reviewing it reads that rule for the detail and its exceptions.

| Id | Requirement | Applies when the change | Covered when | Skill and rule |
|---|---|---|---|---|
| GL1 | Every test boots `EcotoneLite::bootstrapFlowTesting()` and uses only `Api/` types and its own fixtures: no `src/` class, no SQL or schema call on Ecotone tables, no reflection | adds or changes a test | every new or changed test reads that way | `framework-testing 10` |
| GL2 | A guarantee reachable through several entry points has a test per entry point | gives such a guarantee | the tests, one per path | `framework-testing 10a` |
| GL3 | Tests drive messages through Ecotone's testing support: no fork, process, socket or wall-clock loop | adds or changes a test that needs a consumer, a second service or a wait | the test uses `run()` and the pinned clock; real concurrency is named as the exception | `framework-testing 10b` |
| GL4 | Test methods are `snake_case` and describe the behaviour; no comments, docblocks or assertion messages; fixtures are inline anonymous classes, never a shared `Fixture/` directory | adds or changes a test | every new or changed test has that shape | `framework-testing 11` |
| GL5 | A channel is reached through an `#[Asynchronous]` handler and `run('<channel>')`, never by a direct send or receive | tests an asynchronous flow | the test sends the message and runs the channel | `framework-testing T2` |
| GL6 | Time is pinned at bootstrap and asserted as exact values, never tolerances | adds a time-dependent test | the test pins the clock and asserts exact timestamps | `framework-testing T3` |
| GL7 | No service dependency is nullable or defaulted: no `?Service`, no `= null`, no `= new X()` | adds or changes a constructor dependency | every dependency is a required parameter the container passes | `framework-service-design 2`, `framework-service-design 2a` |
| GL8 | A constructor-injected service holds no state: no property written after construction, no static cache, no per-message collector; state it cannot avoid is read from `ExecutionScope` | adds or changes a property or a static on a service | the service has none, or reads its state with `ExecutionScope::stateOf()` | `framework-service-design 3`, `framework-service-design 3a` |
| GL9 | A method either reads or writes: a mutator returns `void` or is named for the mutation, a getter changes nothing, a read runs no DDL | adds or changes a method on a service | each new method is one or the other | `framework-service-design 4` |
| GL10 | A new interface marks a real seam: two implementations, or a dependency inverted across a package or layer | adds an interface | the two implementations or the inverted dependency are named | `framework-service-design 5` |
| GL11 | Configuration is an attribute or an object returned from a `#[ServiceContext]` method, compiled to a `Definition`: no YAML, no XML | adds configuration or registers a service | the configuration has that form | `framework-service-design 13` |
| GL12 | Class metadata comes from `InterfaceToCallRegistry`, never from fresh reflection | reads class or method metadata in framework code | no `new ReflectionClass` or `ReflectionMethod` the registry could answer | `framework-service-design 13a` |
| GL13 | An orchestrating method reads as a short list of named steps; SQL lives in the class that owns the table | adds or changes a coordinating method or SQL | the method is a step list and the SQL sits with its table | `framework-service-design 15` |
| GL14 | Every new PHP class file declares `strict_types=1` and the class is `final` | adds a PHP class file | each new file has both | `framework-service-design 17` |
| GL15 | A call with three or more arguments uses named arguments; enums, readonly and first-class callables are used where they carry meaning | adds such a call or such a construct | the new calls are named | `framework-service-design 18` |
| GL16 | New code has no inline comment and no prose docblock: only array shapes, generics and `@link` to the documentation | adds or changes PHP code | the added lines have none | `framework-public-api 6` |
| GL17 | A concept carries one word from the attribute to the table: class, method, option, console parameter, header and column | introduces a concept or names an existing one | the one word is named and no synonym appears | `framework-public-api 6a` |
| GL18 | A new `Api/` value class has promoted `public readonly` properties and `with*()` methods returning a new instance: no plain getter, no writable property, no docblock restating the signature | adds or changes a class under `Api/` | the class has that shape | `framework-public-api 6b` |
| GL19 | A class an application uses lives under `packages/<Package>/Api/`, a sibling of `src/`, never `src/Api/` | adds a class an application uses | the class is placed there | `framework-public-api 12` |
| GL20 | The boundary holds both ways: no `src/` type in a signature an application calls, no module, resolver or container class under `Api/` | adds or changes a public signature or a file under `Api/` | every public signature uses `Api/` types only, and `Api/` holds no plumbing | `framework-public-api 12a` |
| GL21 | A console option is named as its PHP parameter verbatim, camelCase, never kebab-case | adds or changes a console option | the option equals the parameter name | `framework-public-api 14` |
| GL22 | A framework header is named `ecotone_{module}_{feature}_{action}`, lower snake_case, defined once as a constant | adds a message header | the header has that name and one constant | `framework-public-api 14a` |
| GL23 | An exception message names what was wrong, why it cannot work and every way out (the exact call, attribute, option or command), and a test asserts the text | throws or changes an exception | the message has the three parts and the test asserts it | `framework-error-handling 1` |
| GL24 | A configuration mistake throws a `ConfigurationException` while the application boots: never on the first message, never as an empty result | adds something an application can configure wrongly | the guard runs at bootstrap | `framework-error-handling 1a` |
| GL25 | Context an exception needs at runtime is a value object built when the container is built, not computed when the failure happens | adds an exception that reports context | the context arrives through the `Definition` | `framework-error-handling 1b` |
| GL26 | A check on handler signatures or attribute placement runs in the module's `prepare()` with `InterfaceToCallRegistry`; only message content is checked at runtime | adds a check or a guard | the check is on the compile path, with no runtime branch for it | `framework-error-handling 1c` |
| GL27 | Every placement, parameter type, combining attribute, repeated registration and contradicting setting of a new attribute, configuration or gateway is supported with a test or refused at bootstrap with a message test; an ambiguous usage is refused | adds an attribute, a configuration or a gateway | each usage is listed with its test or its refusal | `framework-error-handling 1d` |
| GL28 | Concurrent writes are guarded by a version check on the write: no pessimistic lock, no lock table | writes to storage that two writers can reach | the write carries the expected version | `framework-storage-and-transactions 8` |
| GL29 | A lost optimistic write throws `ConcurrencyException` and is handled by the existing instant retries, error channel and dead letter: no retry loop, re-send or retry configuration of the feature's own | adds a versioned write | the exception is thrown and tests prove the retry and the dead letter with default configuration | `framework-storage-and-transactions 8a` |
| GL30 | A feature that needs external storage ships an `InMemory*` twin, proved equal by one suite run against both | adds storage | the twin and the shared suite | `framework-storage-and-transactions 9` |
| GL31 | No table is created or looked up on the message path; a missing table raises its table manager's `ConfigurationException` naming the setup command | touches a table manager or a statement on the message path | no `CREATE TABLE` or `tableExists` there | `framework-storage-and-transactions 16` |
| GL32 | Whatever a feature stores about a message is written inside that message's transaction | adds a database write | the write is inside the boundary transaction, or is one of the two named exemptions | `framework-storage-and-transactions 16a` |
| GL33 | No handler, Distributed Bus message or dashboard action runs DDL | adds a flow that reaches a schema change | tables are migrated before the first message | `framework-storage-and-transactions 16b` |
| GL34 | An engine-sensitive change lists its MySQL and MariaDB runs | touches event sourcing, DBAL, transactions or DDL | the runs are listed | `framework-storage-and-transactions 16c` |
| GL35 | Framework code opens no transaction; it runs in the one the boundary opened | adds storage code or a console command | no `transactional()` or `beginTransaction()` outside the listed boundaries | `framework-storage-and-transactions 16d` |
| GL36 | A database connection is asked for per use from the connection factory: never built with `DriverManager`, never kept in a property or handed to a long-lived object | adds code that uses a connection | the dependency is a factory and the connection is resolved inside each method | `framework-storage-and-transactions 16e` |
| GL37 | Enterprise behaviour is a separate class with a `licence Enterprise` header, chosen by `LicenceDecider` when the container is built; open-source files carry `licence Apache-2.0` and no licence branch | adds an Enterprise feature or a new file under `src` or `Api/` | the split and the headers | `framework-enterprise-licensing 7` |
| GL38 | A new module is added to a `ModuleClassList` constant in the same change | adds a module | the constant names it | `framework-module-scaffolding M1` |
| GL39 | A new package starts from `_PackageTemplate/` and is named in `ModulePackageList`, `ModuleClassList::packageNames()`, `forPackage()` and `composerPackageNameOf()` | adds a package | the four registrations | `framework-module-scaffolding M2` |
| GL40 | A module is proved through the application surface and never instantiated or named in its test | adds or changes a module's test | the test boots `EcotoneLite` and asserts behaviour | `framework-module-scaffolding M3` |
| GL41 | A design proposing a table or an index names the existing read path it considered and why that is not enough | adds or changes a design or a spec that proposes storage | the read path and the reason are in the document | `framework-design-and-briefs D1` |
| GL42 | Every guarantee a design states has a test named after it | adds or changes a design or a spec | the guarantees map to named tests | `framework-design-and-briefs D2` |
| GL43 | A behaviour change a 1.x application would notice has an `upgrade-2.0.md` entry (Before / Now / How to adapt), and a rename updates the spec in the same commit | changes observable behaviour or renames something a spec names | the entry and the updated spec | `framework-design-and-briefs 19` |
| GL44 | A new rule a program can decide ships as a check that CI runs, registered in `DETERMINISTIC_CHECKS`, not only as a skill sentence | adds a rule or a convention | the check and its workflow step, or why the rule needs judgement | `framework-agent-tooling A1` |
| GL45 | A judgement rule is added to the skill whose *Applies when* covers it, never to `AGENTS.md` or `docs/` | adds a rule or a convention | the rule sits in that skill | `framework-agent-tooling A2` |
| GL46 | Every new or changed rule has a grader, and its eval cases were run | changes a skill or an eval case | the grader files exist and the scores are reported | `framework-agent-tooling A4` |
| GL47 | An eval case is a self-contained task a developer would give: it carries the code in the prompt and never quotes the rule it grades | adds or changes an eval case | the prompt reads that way | `framework-agent-tooling A5` |
| GL48 | A new phpstan error is fixed in code; `phpstan-baseline.neon` only shrinks | touches PHP code or the baseline | the baseline has no added entry | `framework-dev-environment E4` |
| GL49 | Every class, attribute, method, parameter and console option the change names exists in the tree: no 1.x namespace presented as current | names framework API in code, tests, skills or documents | the names resolve in the tree or in `upgrade/namespace-map-2.0.csv` | `framework-orientation O2` |
| GL50 | Every commit subject of the range is conventional and scoped, and the message lets the defect be reconstructed without the diff | always | the subjects match `type(scope): ` | `framework-pull-request P1` |

### Rules no review decides

Every rule of every project skill is either cited by a requirement above or listed here with the reason, and
`php bin/check-agent-skills.php` refuses a rule that is in neither place. The four review skills are the
exception: their rules say how a section of this list is decided, so they are not cited in it and the check skips
them.

| Rule | Why no review decides it |
|---|---|
| `framework-testing T1` | the order of work, not a property of the change; its proof is BH2 |
| `framework-enterprise-licensing 7a` | about copying Enterprise files out of this repository |
| `framework-design-and-briefs D3`, `framework-design-and-briefs D5` | how a brief and a move of types are written up, before the review |
| `framework-design-and-briefs D4` | carried out by the push process: the decided lists are committed (rule P3) |
| `framework-agent-tooling A3`, `framework-orientation O3` | enforced by `php bin/check-agent-skills.php` in CI |
| `framework-dev-environment 21`, `framework-dev-environment E1`, `framework-dev-environment E2`, `framework-dev-environment 20`, `framework-dev-environment E3` | how commands are run, which leaves nothing in the change |
| `framework-orientation O1` | which skills the implementing session loaded |
| `framework-pull-request P2`, `framework-pull-request P3`, `framework-pull-request P3a`, `framework-pull-request P3b`, `framework-pull-request P4`, `framework-pull-request P5` | the push process this list is part of |

## Breaking changes

Owned by `framework-review-breaking-changes`. "Outside a major" is decided by its rule B1.

| Id | Requirement | Applies when the change | Covered when | Skill to cover it with |
|---|---|---|---|---|
| BC1 | Storage an existing deployment holds stays readable and writable: tables, columns and their types, event store metadata keys, persisted message headers, serialized payload shapes, projection state, document store keys | changes anything written to a table, a queue, the outbox, the dead letter or the event store | outside a major the stored form is unchanged; in a major the old form still has a read path | `framework-storage-and-transactions` |
| BC2 | A storage change in a major has its migration step in `upgrade-2.0.md` | changes a stored form in a major | the entry exists and names the step | `framework-design-and-briefs` |
| BC3 | No class, method or parameter of the public surface is removed or renamed (parameter names included), no type narrowed, no required parameter added | changes a signature under `packages/*/Api/`, an attribute, a gateway, a `#[ServiceContext]` configuration object or an exception an application may catch | each changed signature is named and is safe (a new optional parameter at the end, a new class, a new method on a final class), or the release is a major | `framework-public-api` |
| BC4 | No default value changes | changes a default an application relies on | the default is unchanged, or the release is a major and BC6 is covered | `framework-public-api` |
| BC5 | No console command name or option changes | changes a console command | names and options are unchanged, or the release is a major and BC6 is covered | `framework-public-api` |
| BC6 | A behaviour change an application observes (routing, ordering, transaction scope, retry, error type, timing) has an `upgrade-2.0.md` entry: Before / Now / How to adapt | changes what existing application code can notice, also with identical signatures | the entry exists | `framework-design-and-briefs` |
| BC7 | Outside a major, a behaviour change is an opt-in, never a new default | changes observable behaviour outside a major | the old behaviour stays the default | `framework-service-design` |
| BC8 | Old and new versions run side by side during a rolling upgrade: what one writes to a shared queue or table the other consumes | changes what is written to a shared queue or table | old-reader/new-writer and new-reader/old-writer both hold, or the release is a major and the upgrade note says they do not | `framework-storage-and-transactions` |

## Behaviour

Owned by `framework-review-behaviour`.

| Id | Requirement | Applies when the change | Covered when | Skill to cover it with |
|---|---|---|---|---|
| BH1 | The change reaches the observable outcome that solves the stated problem | always | the diff is traced from the entry point an application uses to that outcome | the skill the changed code belongs to (`framework-orientation` task map) |
| BH2 | Every claimed behaviour has a test that is red without the change | claims a behaviour (a feature, a fix) | per claim: the test, and the proof it was red (its failing output on the base commit, or the commit adding it before the change) | `framework-testing` |
| BH3 | Every entry point the guarantee must hold on is tested: bus send by class, routed send, gateway, console command, asynchronous consumer, the backfill or reconstruction path | gives a guarantee a message can reach by more than one path | per entry point: the test, or why the path cannot reach the change | `framework-testing` |
| BH4 | Every storage and engine is tested: in-memory and DBAL; PostgreSQL, MySQL and MariaDB | touches storage or anything engine-sensitive | per implementation and engine: the test run | `framework-testing`, `framework-storage-and-transactions`, `framework-dev-environment` |
| BH5 | The failure path is tested: the handler throws, the database is down, rollback, retry | can fail part-way or runs inside a transaction | the test | `framework-testing` |
| BH6 | Repeated delivery of the same message is tested | handles a message that can be delivered twice | the test | `framework-testing` |
| BH7 | Concurrency is tested: two consumers or two requests at once | reads or writes shared state or storage | the test | `framework-testing` |
| BH8 | Empty input is tested | takes a collection, an optional value or a stream | the test | `framework-testing` |
| BH9 | A misconfiguration is refused at bootstrap, and a test asserts the message | adds an attribute, a configuration or a usage that can be written wrongly | the test asserting the message | `framework-error-handling`, `framework-testing` |
| BH10 | Asynchronous and synchronous execution are both tested | can run on either | both tests | `framework-testing` |
| BH11 | Time boundaries are tested, with pinned time | depends on time: a delay, a timeout, an expiry, a schedule | the test | `framework-testing` |
| BH12 | Tests assert what an application observes: no SQL on Ecotone tables, no reflection, no `src/` class | adds or changes a test | every new or changed test is at the userland level | `framework-testing` |
| BH13 | Changed skills pass their eval cases, and every new or changed rule has a grader in a case | touches `.agents/skills`, `api/skills`, `.agents/evals` or `api/evals` | `bin/test-agent-skills` scores per case, all at or above the threshold | `framework-agent-tooling` |
| BH14 | Each Ecotone feature the change can meet in one application either works together with it or is refused at bootstrap | adds or changes a feature an application combines with others | per feature: the test of the combination, or the test asserting the refusal message, or `not-apply` as unrelated | `framework-testing`, `framework-error-handling` |
| BH15 | No state of the message being handled sits on a shared service: no property written after construction, no static, no cache, unless it lives in `ExecutionScope` | adds or changes state on a service | the test interleaving two executions | `framework-service-design` |
| BH16 | No connection, channel, transaction or lock is kept across a wait in a property or a long-lived object | acquires or stores such a resource | the test in real coroutines | `framework-storage-and-transactions` |
| BH17 | No decision is made from shared state read before a wait and written after it | reads shared state, waits, then writes | the test interleaving two executions | `framework-service-design` |
| BH18 | Every I/O call the change introduces can be waited on by a coroutine, or is refused with a message naming the way out | introduces an I/O call through an extension or a client | the test in the `swoole` and the `openswoole` container, or the test asserting the refusal | `framework-error-handling`, `framework-dev-environment` |
| BH19 | A consumer that cannot be run by several coroutines is refused by `ConsumerCoroutines` | adds an endpoint or an inbound adapter | the test asserting the refusal, or the test consuming on several coroutines | `framework-error-handling`, `framework-testing` |
| BH20 | Only read-only context is copied into a coroutine a handler starts (`CopiedToChildExecution`) | copies state to a child execution | the test | `framework-service-design` |
| BH21 | A new test that runs in real coroutines is listed in `COROUTINE_TESTS` of `.github/workflows/test-monorepo.yml` | adds such a test file | the file is in the list | `framework-dev-environment` |
| BH22 | A flow or a flow feature the change adds or changes is exercised by a scenario of the flow scenario catalogue, so the pull request's flow comparison shows its before and after | adds or changes a message flow, or a feature that takes part in one (an attribute, a channel type, an interceptor point, a module, an endpoint kind, a routing or delivery behaviour); `not-apply` when it touches no flow | per flow or feature: the scenario (class and method, listed in `all()`), marked `needingACoroutineRuntime()` when it needs real coroutines, or why it cannot be one (an external broker, a second process); the baseline is left to main's CI | `framework-testing T4` |

## Developer experience

Owned by `framework-review-developer-experience`. A change with no end-user surface decides the whole section
`not-apply` in one line, `NO USER-FACING SURFACE`.

| Id | Requirement | Applies when the change | Covered when | Skill to cover it with |
|---|---|---|---|---|
| DX1 | Everything the end user must know for the most common case has a reason: each class imported, option set, service registered, step in order | adds or alters anything an application developer writes, configures or reads | the usage code is written out and each element is justified | `framework-service-design` |
| DX2 | The feature is declared with an attribute or a `#[ServiceContext]` configuration: no service registered by hand, no registry called at bootstrap, no framework base class, no framework interface on a domain class | adds a way to switch a feature on | the usage is declarative, in the shape of the nearest existing feature | `framework-service-design` |
| DX3 | No setting is repeated in two places | adds configuration | each setting is stated once | `framework-service-design` |
| DX4 | The most common case works with no options set: every option has a default most applications want, and an option is required only when the framework cannot know the value | adds or changes options | per required option: why the framework cannot know it | `framework-service-design`, `framework-error-handling` |
| DX5 | There is no option nobody would change | adds options | each option has a use | `framework-public-api` |
| DX6 | The defaults agree with the defaults of the features around it | adds or changes a default | the neighbouring defaults are named and agree | `framework-public-api` |
| DX7 | A default that affects failure handling, delivery, transactions or data safety is the resilient side: nothing loses a message, loses data or hides a failure | adds or changes such a default | per default: its value and why it is the safe side | `framework-error-handling` |
| DX8 | The less safe behaviour is an explicit opt-in whose name says what is given up | offers a less safe behaviour | the opt-in and its name | `framework-public-api` |
| DX9 | Every name (feature, attribute, parameter, configuration method, console option) says what it does in the application developer's vocabulary: no abbreviation, no implementation term, no negated boolean | adds or changes a name an end user reads | per name: it reads without the implementation | `framework-public-api` |
| DX10 | A concept keeps the name Ecotone already uses for it | adds a name | the existing attributes and public `ecotone-*` skills were searched | `framework-public-api` |
