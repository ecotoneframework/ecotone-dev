# Wave 20 report: the licence notice, AGENTS.md rule 7a, the quickstarts, the Symfony test application

Branch `dgafka/licence-env-wave20`, base `a7f292745`. Not pushed, not merged.

| Commit | Unit |
|---|---|
| `9663e44ea` fix(licence): the add script replaces a superseded Enterprise notice instead of stacking a new one above it | U1 tooling |
| `1bde3ddd9` docs(licence): the Enterprise notice says what to do instead when the licence cannot be confirmed | U1 |
| `48c32fabd` docs(licence): AGENTS.md says Enterprise files are edited normally in this repository and copied out nowhere | U2 |
| `a02b1a75c` fix(tracing): the forced trace flush refuses a tracer provider that cannot flush, naming how to bind one | U4 framework |
| `bdd30a6b1` test(symfony): the shared Symfony test application sends a command and a query through the buses | U4 application |
| `bef38f577` fix(licence): an Enterprise refusal names how to pass the licence key on every integration | U3, per the coordinator |
| `95aefad89` docs(2.0): forced trace flushing refuses a tracer provider that cannot flush | U4 upgrade note |
| `341651be2` fix(quickstart): the examples run on 2.0, and a failing one exits non-zero | U3 |
| `83b6b4b79` docs(2.0): R32-R34 from running every quickstart, and the ledger after wave 20 | U3 findings |
| `48536d33c` fix(modelling): R32 a document-stored aggregate or saga using WithEvents loads back under 2.0's JMS null default | U3, per the coordinator |
| `7df7b9e9b` fix(dbal): R35 a document that cannot be read back is refused naming its id, collection and type | U3, per the coordinator |
| `b3ab98678` docs(2.0): R32 and R35 fixed in wave 20; five quickstarts still stop, all on R34 | U3 findings |

## U1 — the notice's instruction

**Re-derived:**
- The notice has one source, `bin/enterprise-licence-notice.php`, which both `bin/check-licence.php` and
  `bin/add-enterprise-licence.php` require. This held.
- **"480 files, one md5" did not reproduce.** The real count is **462** files whose first licence marker is
  `Enterprise`, sharing one md5 of the notice body (`638e3f3d…` before the change).
- **The propagation path did not work.** `completeEnterpriseNoticeInFile()` knew only a bare marker and a marker
  followed by nothing. A changed notice would have been inserted *above* the old body, leaving both texts in all 462
  files.

**What changed:**
- `9663e44ea` teaches the script to find a superseded notice by its closing line, inside the same docblock, and
  replace it. On the unchanged notice it rewrites nothing; that was verified before the commit.
- `1bde3ddd9` adds the one imperative sentence: *"If you cannot confirm that licence, implement the behaviour from
  your own functional requirements instead, without copying from or adapting this file."* It sits after the
  keep-the-notice/renaming paragraph. Text change and propagation are one commit, 463 files, 1389 insertions, zero
  deletions.

**Proof:**
- One md5 across all 462 files (`34d7951f…`).
- A second run changes nothing: `git diff | md5sum` was identical before and after it.
- `php bin/check-licence.php` exits 0.
- `ErrorChannelTest.php` (Ecotone and Amqp) and `DelayedRetryDelayAndDeadLetterTest.php` are still
  `licence Apache-2.0`, carry no notice, and are untouched.
- `bin/enterprise-licence-scope.php` was not touched.

## U2 — AGENTS.md rule 7a

**Re-derived:** `CLAUDE.md` is a symlink to `AGENTS.md`, and `.cursorrules` and `.augment-guidelines` are pointer-only
files. That still holds.

**What changed:** row 7a, placed directly under rule 7. It says this is the Ecotone core repository and that
`licence Enterprise` files are edited here like any other file; the notice is not addressed to work in this
repository. It governs copying *out*: no copy, no adaptation, no "inspired" reimplementation, renaming does not make
it permitted, and without a confirmed licence you implement from your own requirements. The "why" column names both
failure directions.

**Not done:** `docs/coding-conventions.md` calls itself the single source for the rules and `AGENTS.md` the summary,
and it has no 7a. The brief said one file, so I left it; the maintainer may want the full rule there.

## U4 — the shared Symfony test application

**Re-derived:**
- Every `file:line` in the brief held: docblock `:17`, constructor `:19`, `forceFlush()` `:29`,
  `services.yaml:25-26`, and the registration at `OpenTelemetryModule.php:94-117`.
- **One premise did not.** The bound provider lives in the host container. No module sees that binding in
  `prepare()`, and `InterfaceToCallRegistry` describes classes, not container bindings, so rule 1c's compile-time check
  cannot reach it.
- The refusal therefore comes when the forced-flush interceptor is first resolved. The gateway proxy resolves it
  lazily, so that is the first bus call, but before the handler runs. Before the fix, the handler ran and then the
  flush crashed.

**Root cause in the application:**
- 1.x listed `tracing` in `skippedModulePackageNames`. `f035855fb`'s skip-to-allow conversion carried it into
  `modulePackages`, so the 2.0 application loads a package it had always skipped.
- `ecotone/symfony-bundle` does not require `ecotone/open-telemetry`, so the per-package run never loaded it. Only
  the root run did.
- `34837dda5` then bound `NoopTracerProvider` purely so that container would build. Both are gone; that is the
  "stop loading the tracing package" option. ExampleApp already proves tracing inside a Symfony kernel with an SDK
  provider.

**What changed:**
- `EcotoneForcedTraceFlush::create()` refuses a non-SDK provider with a `ConfigurationException`. The message names
  the bound class, an SDK provider to bind, and the `TracingConfiguration` calls that turn the forced flush off. The
  property is typed to the SDK interface and the docblock is gone.
- An `upgrade-2.0.md` entry was appended at the end of section 14.

**RED then GREEN:**
- `ForcedTraceFlushTest::test_forced_flush_refuses_a_tracer_provider_that_cannot_flush_before_any_message_is_sent`,
  on the base: `Error: Call to undefined method OpenTelemetry\API\Trace\NoopTracerProvider::forceFlush()` at
  `EcotoneForcedTraceFlush.php:29`.
- Its two companion tests (the switch-off way out, and the SDK reference) passed on the base too; they are not
  claimed as RED.
- `SymfonyApplicationTest::test_it_sends_a_command_and_a_query_through_the_buses`, on base tracing code: the same
  undefined-method error. After the framework fix it failed with the new `ConfigurationException` message, and after
  the binding change it passes.

**Not done:** `TracingConfiguration` (rule 12a, untriaged) was not touched. Wave 13's `ConnectionExceptionRetryTest`
still asserts on `ecotone:run`'s exit code; it could now assert on an outcome, but that was outside the brief.

## U3 — the quickstart examples

**Re-derived:** wave 13's two errors reproduced, but **"a signature that moved in 2.0" is not the cause**.
`EcotoneLite::bootstrap()` has the same signature on `main`. The examples called `EcotoneLiteApplication::bootstrap()`,
deleted in 2.0. That method loaded the `src` catalog and autowired services, and `e85ef8592` swapped the class name
mechanically. So every `EcotoneLite` example failed, not five. The second error is the services array landing in
`$classesToResolve`. I did not change the finder.

**Scope:**
- All 43 distinct examples in `tests:ci` (45 entries) were run, after `composer update`, with `ECOTONE_LICENSE_KEY`
  set as CI now sets it. The three Tempest examples, which CI does not run, were run as well.
- Before: 11 of 45 entries exited 0, and **six of those were false passes**:
  - the four Laravel multi-tenant examples printed a licence refusal and exited 0;
  - both Laravel projection examples printed `bootstrap/cache directory must be present and writable` and exited 0.
- Cause: Laravel's HTTP kernel installs an exception handler that renders and returns, so PHP exits 0. Each script
  now restores PHP's handler after the bootstrap.
- After: 40 of 45 entries exit 0 (38 of 43 distinct examples), and all three Tempest examples run. The full sweep
  measured 36; the four R32 examples were then fixed and rerun, each twice.

**R32, fixed in this wave** (`48536d33c`): `BuildingBlocks`, `Testing`, `Workflows/Saga` and `MicroservicesAdvanced` now
run, twice each. Their phpunit suites are green: 11, 25 and 7 tests.

**Still failing (5), all on R34, recorded and not worked around:**
- R34: `MultiTenant/Laravel/AsynchronousEvents`, `MultiTenant/Laravel/EventSourcing`,
  `MultiTenant/Symfony/AsynchronousEvents`, `MultiTenant/Symfony/EventSourcing`,
  `MultiTenant/General/HookIntoTenantSwitch`.
- **Five of the thirteen multi-tenant examples remain failing.** The other eight run with the key, and without it
  stop at the rule-1 `LicensingException`.

**Wave 21:**
- Files under `quickstart-examples` that name connection factories by FQCN, most of them from before this wave:
  `Ecotone\Dbal\Connection\DbalConnectionFactory` in 45, `Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory` in
  19, `Ecotone\Amqp\Connection\AmqpExtConnectionFactory` in 6, `Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory`
  in 2.
- The new `Tempest/MultiTenant/MessageBus/app/ecotone.config.php` names `Ecotone\Tempest\EcotoneConfig`, also in
  `src` (a rule-12a candidate).

Per example, the first error before the fixes and the last line after:

| example | exit before | first error before | exit after | last line after |
|---|---|---|---|---|
| Asynchronous | 255 | Error: Object of class Ecotone\Amqp\Connection\AmqpExtConnectionFactory could not be converted to string | 0 | Handling asynchronously: Milk |
| AuthoritativeClassmap | 255 | Error: Unknown named parameter $serviceConfiguration | 0 | Customer registered |
| Conversion | 255 | Ecotone\AnnotationFinder\ConfigurationException: Loading catalog was turned off and no namespaces were provide | 0 | Product ids: 1718f7d8-60ff-479a-93a3-630040a17aa2,817e56cf-badb-43e2-a9fb-56230a |
| CQRS | 255 | Ecotone\AnnotationFinder\ConfigurationException: Loading catalog was turned off and no namespaces were provide | 0 | Milk |
| EmittingEventsFromProjection | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Balance after change is  140 |
| EventHandling | 255 | Ecotone\AnnotationFinder\ConfigurationException: Loading catalog was turned off and no namespaces were provide | 0 | Milk |
| EventSourcing | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Price of the product was changed |
| EventProjecting/PartitionedProjection | 255 | Error: Unknown named parameter $objectsToRegister | 0 | === Example completed successfully! === |
| WorkingWithAggregateDirectly | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Success! |
| MessageBroker | 0 | ran | 0 | Consumer finished. |
| Microservices | 255 | Error: Unknown named parameter $serviceConfiguration | 0 | No orders left, all good |
| MicroservicesAdvanced | 255 | Error: Unknown named parameter $serviceConfiguration | 0 | 0 after the R32 fix (`48536d33c`); 255 in the sweep, on R32 |
| Schedule | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Invoice generated for user |
| StatefulProjection | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Rerun the example to register new ones |
| OutboxPattern | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Done. |
| RefactorToReactiveSystem | 255 | Error: Unknown named parameter $serviceConfiguration | 0 | Shipping products to Washington 15! |
| ErrorHandling | 255 | Error: Unknown named parameter $serviceConfiguration | 0 | Success! Message was successfully replayed from DLQ |
| Testing | 255 | Ecotone\Messaging\Config\ConfigurationException: The 'ecotone_event_stream' table required by the 'event_strea | 0 | 0 after the R32 fix (`48536d33c`); 255 in the sweep, on R32 |
| BuildingBlocks | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | 0 after the R32 fix (`48536d33c`); 255 in the sweep, on R32 |
| BusinessInterface/ServiceActivator | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Cache set and get successfully |
| BusinessInterface/Routing | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Cache set and get successfully |
| BusinessInterface/Asynchronous | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 0 | Cache set and get successfully |
| MultiTenant/Laravel/MessageBus | 0 | exit 0, but: In LicensingException.php line 31: Multi-tenancy requires Ecotone Enterprise licence when using MultiTenantCon | 0 | Running demo: |
| MultiTenant/Laravel/Aggregate | 0 | exit 0, but: In LicensingException.php line 31: Multi-tenancy requires Ecotone Enterprise licence when using MultiTenantCon | 0 | Running demo: |
| MultiTenant/Laravel/Events | 0 | exit 0, but: In LicensingException.php line 31: Multi-tenancy requires Ecotone Enterprise licence when using MultiTenantCon | 0 | Sending welcome notification to customer 2 for tenant tenant_b |
| MultiTenant/Laravel/AsynchronousEvents | 1 |  | 255 | R34: `The 'ecotone_deduplication' table … does not exist` |
| MultiTenant/Laravel/EventSourcing | 0 | exit 0, but: In LicensingException.php line 31: Multi-tenancy requires Ecotone Enterprise licence when using MultiTenantCon | 255 | R34: `The 'ecotone_event_stream' table … does not exist` |
| MultiTenant/Symfony/MessageBus | 255 | Ecotone\Messaging\Support\LicensingException: Multi-tenancy requires Ecotone Enterprise licence when using Mul | 0 | [info] Disconnecting |
| MultiTenant/Symfony/Aggregate | 255 | Ecotone\Messaging\Support\LicensingException: Multi-tenancy requires Ecotone Enterprise licence when using Mul | 0 | [info] Disconnecting |
| MultiTenant/Symfony/Events | 255 | Ecotone\Messaging\Support\LicensingException: Multi-tenancy requires Ecotone Enterprise licence when using Mul | 0 | [info] Disconnecting |
| MultiTenant/Symfony/AsynchronousEvents | 255 | Ecotone\Messaging\Support\LicensingException: Multi-tenancy requires Ecotone Enterprise licence when using Mul | 255 | R34: `The 'enqueue' table … does not exist` |
| MultiTenant/Symfony/EventSourcing | 255 | Ecotone\Messaging\Support\LicensingException: Multi-tenancy requires Ecotone Enterprise licence when using Mul | 255 | R34: `The 'ecotone_event_stream' table … does not exist` |
| MultiTenant/General/HookIntoTenantSwitch | 255 | Error: Object of class Ecotone\Dbal\Connection\DbalConnectionFactory could not be converted to string | 255 | R34: `The 'ecotone_document_store' table … does not exist` |
| MultiTenant/General/MessageBusForMultipleTenants | 255 | Error: Unknown named parameter $objectsToRegister | 0 | [32minfo:[0m Deactivating tenant `tenant_b` on connection `tenant_b_factory` |
| ConsoleCommand/Laravel | 0 | ran | 0 | Hello, John! You have selected custom and 1,2 types. |
| ConsoleCommand/Symfony | 0 | ran | 0 | [info] Disconnecting |
| Workflows/SynchronousStateless | 255 | Error: Unknown named parameter $objectsToRegister | 0 | Demo finished. |
| Workflows/AsynchronousStateless | 255 | Error: Unknown named parameter $objectsToRegister | 0 | Demo finished. |
| Workflows/Saga | 255 | Error: Unknown named parameter $objectsToRegister | 0 | 0 after the R32 fix (`48536d33c`); 255 in the sweep, on R32 |
| Laravel/Projection/DatabaseReadModel | 0 | exit 0, but: In PackageManifest.php line 179: The /data/app/quickstart-examples/Laravel/Projection/DatabaseReadModel/boot s | 0 | == Example completed successfully == |
| Laravel/Projection/EloquentReadModel | 0 | exit 0, but: In PackageManifest.php line 179: The /data/app/quickstart-examples/Laravel/Projection/EloquentReadModel/boot s | 0 | == Example completed successfully == |
| Symfony/Projection/DatabaseReadModel | 255 | Uncaught Exception: The 'ecotone_projection_state' table required by the 'projection_state' feature does not e | 0 | [info] Disconnecting |
| Symfony/Projection/EntityReadModel | 255 | Uncaught Exception: The 'ecotone_projection_state' table required by the 'projection_state' feature does not e | 0 | [info] Disconnecting |
| Tempest/Model (not in CI) | n/a | not measured before | 0 | == Example completed successfully == |
| Tempest/MultiTenant/MessageBus (not in CI) | n/a | not measured before | 0 | == Example completed successfully == |
| Tempest/Projection/DatabaseReadModel (not in CI) | n/a | not measured before | 0 | == Example completed successfully == |

The last-line column is the script's final output line. A Symfony example's final line is its logger's
`[info] Disconnecting`; its success is the exit status. Commands: the runner did `composer update
--ignore-platform-req=ext-sockets` and then the exact `tests:ci` command, inside the `app` container, with
`ECOTONE_LICENSE_KEY` set. Each example with a database table was also run twice to prove a rerun works.

## Verification

Root suites, one at a time, after `packages/DataProtection/tests/before-tests.sh` (exit 0), on the final tree
(including the R32 and R35 fixes):

| suite | exit | summary line |
|---|---|---|
| Core tests | 0 | OK, but some tests were skipped! Tests: 1672, Assertions: 2819, Skipped: 1. |
| DataProtection | 0 | OK (1394 tests, 207742 assertions) |
| Dbal tests | 0 | OK, but some tests were skipped! Tests: 487, Assertions: 1060, Skipped: 1. |
| Event Sourcing tests | 0 | OK, but some tests were skipped! Tests: 635, Assertions: 1288, Skipped: 20. |
| Jms Converter tests | 0 | OK (54 tests, 115 assertions) |
| Laravel tests | 0 | OK, but there were issues! Tests: 48, Assertions: 95, Skipped: 1, Risky: 19. |
| Tempest tests | 0 | OK, but there were issues! Tests: 64, Assertions: 128, Risky: 3. |
| Symfony tests | 0 | OK (56 tests, 106 assertions) |
| Enqueue tests | 0 | OK (1 test, 3 assertions) |
| Amqp tests | 0 | OK, but some tests were skipped! Tests: 150, Assertions: 222, Skipped: 34. |
| SQS tests | 0 | OK, but there were issues! Tests: 42, Assertions: 95, Deprecations: 2. |
| Redis tests | 0 | OK, but there were issues! Tests: 25, Assertions: 38, Deprecations: 5. |
| Monorepo tests | 0 | OK, but there were issues! Tests: 97, Assertions: 3339, Deprecations: 6, Skipped: 3, Risky: 15. |
| OpenTelemetry tests | 0 | OK (38 tests, 292 assertions) |
| Kafka tests | 0 | OK (60 tests, 140 assertions) |

Also run:
- OpenTelemetry package `composer tests:ci`: phpstan `[OK] No errors`, `OK (38 tests, 292 assertions)`.
- Symfony package `composer tests:ci`: phpstan `[OK] No errors`, `OK (56 tests, 106 assertions)`. It first failed
  with `Cannot redeclare interface Psr\Container\ContainerInterface`, from a kernel cache that root-vendor runs had
  left in `packages/Symfony/var/cache`; it passed once that cache was cleared.
- Root phpstan: `[OK] No errors`, and the same for the Ecotone and Dbal per-package configs after the R32/R35 fixes.
- `bin/check-suite-parity.php`: exit 0, "(5 checks)".
- `bin/check-licence.php`: exit 0.
- php-cs-fixer, dry run on the 7 changed PHP files the config covers: "Found 0 of 7 files that can be fixed". It ran
  in the container with explicit paths, because this host has no PHP.

## Findings beyond the brief

- R32 to R35 are in the review. R32 and R35 were fixed in this wave, at the coordinator's request.
- The ledger records:
  - an object default value on a handler parameter is refused with an opaque message;
  - an unconfirmed delayed-release clock boundary;
  - the licence requirement of sixteen of 46 examples, a product question for the maintainer.
- `DatabaseSetupManager` is not `final` (rule 17) and carries a descriptive docblock (rule 6). Noted, not fixed.
- `EventProjecting/PartitionedProjection` still falls back to `LicenceTesting` inside the monorepo; that predates
  this wave.
