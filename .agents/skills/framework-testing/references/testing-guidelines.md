## Testing Guidelines

- **Write the test first**: RED, then GREEN, then refactor, one increment per commit, and prove the test is red
  against the base commit before making it pass. A pure refactor with no behaviour change rides on the tests that
  already cover it
- Tests use **`EcotoneLite::bootstrapFlowTesting()`**, the only flow-testing bootstrap. The connections it is given
  decide the storage: pass a `DbalConnectionFactory` and the event store, document store, deduplication and dead
  letter run on the database; pass none and they run in memory
- **Assert only on what the application observes.** No SQL against Ecotone's own tables, no reflection, no
  statement counting, no internal service references — fetch a gateway (`$ecotone->getGateway(EventStore::class)`,
  `Ecotone\Api\EventSourcing\EventStore`) and drive the public API. This is the most-corrected rule in the
  repository; see conventions rule 10
- **Never test an internal class directly**, not even a seam with two implementations. An in-memory store and its
  DBAL twin are proved equal by one suite that boots the application twice, once per storage, and drives both through
  the same gateway — never by constructing the stores and calling them. A failure the test needs (a database outage,
  a broken send) is produced through something the application owns: a Doctrine connection handed to Ecotone, an
  `#[Around]` interceptor, a handler that throws — never by dropping or editing Ecotone's tables. If a behaviour
  cannot be reached that way, it is not a behaviour the application can observe; remove the code rather than test it
  from the inside
- **A message channel is never driven directly.** `FlowTestSupport` has no `getMessageChannel()`,
  `receiveMessageFrom()` or `sendMessageDirectToChannel()`. The application reaches a channel through an
  `#[Asynchronous]` handler, so the test sends the command or event, calls `run('channel')`, and asserts on what that
  handler observed, through a query handler or the fixture's state. A delayed message is proved the same way: `run()`
  before `advanceTimeBy()` shows nothing handled, `run()` after it shows the handler ran, on DBAL channels as on
  in-memory ones. Only Ecotone's own channel and interceptor suites drive a channel, through the internal
  `Ecotone\Test\ChannelTestSupport::of($ecotone)` (conventions rule 10)
- **Time is pinned at bootstrap** with `bootstrapFlowTesting(initialTime: ...)`, read with
  `$ecotone->now()`, moved with `advanceTimeBy()`, and history is seeded with `withEventsFor(..., at: ...)`. One clock
  stamps every message and event, so a test asserts exact timestamps instead of tolerances
- **A test that needs a raw `Message` builds it with `Ecotone\Test\StaticMessageBuilder`.** `MessageBuilder` is a
  container service holding the clock, so it has no static constructors; the helper wraps one on a native clock for
  the rare internal test that drives a channel interceptor. A message without a timestamp is refused by
  `MessageHeaders::create()`
- **Fixtures live in the test file, as inline anonymous classes** — aggregates included, registered through
  `$fixture::class`. Never a shared `Fixture/` directory. A named class below the `TestCase` is for the four cases
  that force one: a class used as a **type declaration** (every command, event and query), a class named inside
  another fixture's **attribute argument**, which has to be a constant expression, an **event-sourced aggregate on
  DBAL without `#[AggregateType]`**, whose NUL-byte default type PostgreSQL and SQLite cannot store, and a fixture in
  **syntax newer than PHP 8.2**, in a file of its own only the `#[RequiresPhp]` test loads. 92 of the 141 test files
  added since 1.x use named fixtures anyway; that is drift to reduce, not precedent
  ([rule 11](rules.md#11-test-shape) has the exceptions and what is wrongly believed to be one)
- `snake_case` method names, named after the behaviour rather than the class that implements it
- No comments, no docblocks, no assertion messages. The method name is the description
- **One test per path the guarantee has to hold on**, not one per change: a class-routed `CommandBus::send()`, a
  routed send, a gateway, a console command and an aggregate save all reach the same behaviour differently, and the
  backfill path of a concept has to be proved alongside its live path (conventions rule 10a)
- **Messages run through Ecotone's testing support, never around it**: `run()` is the only consumer, time is the pinned
  clock, and a flow across services boots each application in the test process. No fork, `Process`, socket or
  hand-written polling loop; a missing capability is a framework increment (conventions rule 10b)
- **A seam with two implementations is asserted in one suite both of them run** — an in-memory store that nobody
  proves equal to the DBAL one makes every flow test using it suspect (conventions rule 9)
- Run the suite of the package you modified, **one package at a time, never in parallel** — the packages share the
  compose services and a parallel run produces false failures
- **A flaky test is a defect, and a failed check is never rerun.** A test that passes on a second run has a
  timing assumption, an order dependency or a shared resource in it, and a rerun hides it until it fires on someone
  else's PR. Reproduce it instead: force the timing (a 1 ms execution limit, `--order-by=reverse`, the job's engine
  and PHP version, the per-package install Split Testing does), find the cause, fix it, push the fix. The same holds
  for an install or infrastructure failure in CI: the workflow is code in this repository, and it gets the fix.
  [When a check is red](../../framework-dev-environment/references/workflow.md#when-a-check-is-red) has the commands

Conventions [rule 11](rules.md#11-test-shape) has a full worked example.
[the dev workflow](../../framework-dev-environment/references/workflow.md) has every command, the database DSNs, and what each package's
`composer tests:ci` actually runs.

Static analysis is **phpstan level 3 over `src` and `Api/`, over a baseline** — not `tests/`. Level 3 is the first
that checks return types, so it reports a caller that declares a subclass and receives the parent from an inherited
`: self` method — at the caller, never at the `: self` declaration. The errors already in the tree are each config's
`phpstan-baseline.neon`: the list to work down, never to add to. A new error is fixed, not baselined. phpstan still
will not catch a wrong class name in an attribute argument, because attribute arguments resolve lazily via
reflection, so **a green phpstan does not prove a rename or a move is complete** — grep the old FQCN.

