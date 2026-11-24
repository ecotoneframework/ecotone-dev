PASSED

Behaviour review of `git diff main...HEAD` (d5bffa12b, 19d745db2) on branch dgafka/ecotone-lite.

## Problem and outcome

A developer who passed a handler instance to `EcotoneLite::bootstrap()` or `bootstrapFlowTesting()` in
`containerOrAvailableServices` also had to repeat its class in `classesToResolve`. Forgetting it ended in "No Command
Handler is registered" (or, for `bootstrap()`, in "Loading catalog was turned off and no namespaces were provided").
To count as solved: a handler passed only as a service, unkeyed or under its own class name, handles commands
and queries sent through the buses, and does it on the instance that was passed. A service under another name stays
a plain dependency.

Trace: both public entry points go through `EcotoneLite::prepareConfiguration()`
(`packages/Ecotone/Api/Lite/EcotoneLite.php:62`, `:107`). There `classesToResolve` is now
`withClassesOfProvidedServices()` (`:178`, `:299-312`). That method adds `get_class()` of every object whose key is
an int or equals its own class, skips a `ContainerInterface`, and de-duplicates. The merged list goes to
`ContainerCacheLayout::resolve()`, so it also goes into the cache hash, and from there to
`FileSystemAnnotationFinder::init()`, which treats it as user classes (`array_unique` with namespace-scanned classes,
`FileSystemAnnotationFinder.php:135-148`). `InMemoryPSRContainer::createFromAssociativeArray()` keys an unkeyed
object by `get_class()` (`InMemoryPSRContainer.php:33`), so the reference the resolved handler is wired to is the
passed instance. The tests assert that by querying state the command wrote. The change reaches the outcome.

## Decisions

BH1 — covered — trace above: `EcotoneLite.php:178,299-312`. Both entry points pass through it. The outcome is
      asserted by ServicePassedToBootstrapTest::test_handler_passed_as_a_service_is_registered_without_listing_its_class
      (flow testing) and ::test_handler_passed_as_a_service_is_registered_outside_of_flow_testing (`bootstrap()`).
BH2 (unkeyed service, flow testing) — covered —
      ServicePassedToBootstrapTest::test_handler_passed_as_a_service_is_registered_without_listing_its_class. Red
      proof from the implementer, against the base commit: DestinationResolutionException "Can't send command to
      ticket.open. No Command Handler is registered for it in this Ecotone Lite bootstrap"
      ('Tests: 4, Assertions: 1, Errors: 3'). Re-run on this tree: OK (4 tests, 4 assertions).
BH2 (service under its own class name) — covered —
      ServicePassedToBootstrapTest::test_handler_passed_as_a_service_under_its_own_class_name_is_registered_without_listing_its_class,
      red on the base commit with the same DestinationResolutionException.
BH2 (`bootstrap()`, not flow testing) — covered —
      ServicePassedToBootstrapTest::test_handler_passed_as_a_service_is_registered_outside_of_flow_testing, red on
      the base commit with ConfigurationException 'Loading catalog was turned off and no namespaces were provided'.
BH2 (service under another name stays a dependency) — covered —
      ServicePassedToBootstrapTest::test_service_passed_under_another_name_is_only_a_dependency. This behaviour is
      unchanged and the test passes either way, as the brief says. It guards that the new rule does not over-reach,
      so no red run is needed. The other unchanged claim, that a ContainerInterface is not enumerated, has no
      dedicated test. It is the early return at `EcotoneLite.php:301-303`, which keeps the pre-change behaviour.
BH3 (`EcotoneLite::bootstrapFlowTesting()`) — covered — the three flow-testing tests of ServicePassedToBootstrapTest.
BH3 (`EcotoneLite::bootstrap()`) — covered —
      ServicePassedToBootstrapTest::test_handler_passed_as_a_service_is_registered_outside_of_flow_testing.
BH3 (send by routing key, command and query bus) — covered — all four tests send with routing through
      FlowTestSupport and, in the `bootstrap()` test, through CommandBus and QueryBus. Send by class, a gateway, a
      console command and an asynchronous consumer reach the change only through handler registration, which happens
      before any message is sent. They are the same registration as listing the class in `classesToResolve`. The
      codemod commit moves 144 test files with `#[Asynchronous]` handlers (and gateway and console-command tests)
      onto this path, and those suites ran OK on the final tree.
BH10 — covered — synchronous: ServicePassedToBootstrapTest. Asynchronous: after 19d745db2, 144 rewritten test files
      with `#[Asynchronous]` handlers register their handler only through this path, e.g.
      `packages/Ecotone/tests/Messaging/Collecting/CollectingTest.php`. I re-ran it on this tree: OK (26 tests,
      31 assertions). I also probed outside the suite: an `#[Asynchronous]` handler passed unkeyed is queued and
      handled after `run('async')`.
BH12 — covered — ServicePassedToBootstrapTest uses only EcotoneLite, FlowTestSupport and the buses, with an inline
      anonymous handler and a `DestinationResolutionException` expectation (the exception an application gets). There
      is no reflection, no SQL and no `src/` class. The three PdoEventSourcing tests change only which services they pass.
BH14 — covered — the change sits before every feature: it only adds classes to the annotation finder, exactly as if
      they were listed in `classesToResolve`. It is proven combined with the features through the rewritten tests
      of 19d745db2, which now register their handlers only this way, and their suites ran OK on the final tree
      (Ecotone 2748 OK; Dbal, PdoEventSourcing, Amqp, Kafka, Redis, Sqs, JmsConverter, OpenTelemetry, Symfony,
      Laravel, Tempest, CrossModuleTests OK). Counts of rewritten files by feature: asynchronous 144, interceptors
      46/`#[Around]` 22, aggregates 50, error channels 34, distribution 29, projections 28, multi-tenancy 13,
      sagas 9, outbox 6, business methods 5. Event-sourcing tagging (DCB) is covered by the three adapted
      PdoEventSourcing tests, which now pass a handler only in the scenarios that register it. No feature has to
      be refused in combination with it.
BH4 — not-apply — the change touches no storage and nothing engine-sensitive; it is class resolution at bootstrap.
BH5 — not-apply — the change adds no step that can fail part-way and runs in no transaction.
BH6, BH7 — not-apply — the change handles no message and adds no shared state or storage; it runs once, at bootstrap.
BH8 — covered — the empty case, no services or a ContainerInterface, leaves `classesToResolve` as it was. Every
      existing test that passes an empty `containerOrAvailableServices` exercises it (Ecotone suite OK). Non-object
      values are skipped by the `is_object` guard. I probed outside the suite that Closures passed unkeyed or keyed
      bootstrap cleanly.
BH9 — not-apply — the change adds no attribute or configuration that can be written wrongly. A handler under a
      foreign key stays unregistered, as before, and the existing MissingHandlerMessage still names the way out
      (`classesToResolve`), asserted as an exception by test_service_passed_under_another_name_is_only_a_dependency.
BH11 — not-apply — nothing depends on time.
BH13 — not-apply — the diff touches no `.agents/skills`, `api/skills`, `.agents/evals` or `api/evals` file.
BH15 to BH21 — not-apply — `withClassesOfProvidedServices()` is a pure static function run once at bootstrap. It adds
      no service state, no wait, no I/O, no consumer, no child-execution copy and no coroutine test file.
BH22 — not-apply — no message flow or flow feature changes. The change alters only which classes the annotation
      finder reads. Every scenario in `packages/Ecotone/src/Test/Flow/*` (Coroutine, Projection, Endpoint, Gateway)
      already lists each service it passes in `classesToResolve`, so the flow comparison has no before/after to show.

## Not on the list

- Codemod sample (19d745db2): I read the hunks of FetchedAggregateBoundaryTest, DecisionBoundaryTest,
  DecisionModelArrayTagTest, DecisionModelBasicTest and DecisionModelEventMatchingTest. Each removed entry is a class
  whose instance is passed unkeyed in the same call. Non-service classes (aggregates, events) are kept. Some
  one-line lists were split to one entry per line.
- Last-wins on duplicates is unchanged and is not new behaviour: one class passed both unkeyed and under its own
  class name (two instances) is registered once, and the container keeps the second instance, as before.
- A subclass instance passed unkeyed registers the handler methods it inherits from its parent (probed: the command
  reaches the passed instance).
- The DataProtection FileTest errors reported by the implementer are in a file the diff does not touch. I did not
  re-run them.
