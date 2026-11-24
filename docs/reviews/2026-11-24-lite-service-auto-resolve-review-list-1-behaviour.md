NOT PASSED: BH2, BH12

Behaviour review of `git diff main...HEAD` on branch dgafka/ecotone-lite. Round 1 covered d5bffa12b and 19d745db2
(reworded to e3d6b319d, same content). The re-review covers `git diff 5218f2a38..HEAD`: 39abb2456 (the missing-handler
message names passing the instance) and d3b7666f1 (the skills pass a handler once).

## Re-review (5218f2a38..HEAD)

Round 1 decided no requirement `missing`, so this round decides again the requirements the new commits touch: BH2,
BH9, BH12 and BH13, plus BH22, which the reworded message could have touched. The other decisions below are
unchanged.

New claim: when a handler class is found but not registered, the message tells the developer to pass an instance in
`containerOrAvailableServices`. It names `classesToResolve` for an aggregate or a saga, and for a service from a
passed container. This is in `packages/Ecotone/src/Modelling/Config/Routing/MissingHandlerMessage.php:30-34`, and the
aggregate check is `getAttributes(Aggregate::class, IS_INSTANCEOF)`. When no handler exists at all, the message also
names passing an instance (`:43`).

Runs on this tree, in the container, one at a time:
- `packages/Ecotone/tests/Lite`: OK (96 tests, 151 assertions).
- `packages/PdoEventSourcing/tests/Integration/Tagging/DynamicConsistencyBoundaryDisabledDbalTest.php` (the
  `strict: true` change): OK (6 tests, 10 assertions).
- `bin/test-agent-skills` for ecotone-testing, ecotone-interceptors, ecotone-metadata, ecotone-resiliency,
  ecotone-distribution, ecotone-asynchronous, ecotone-event-sourcing, ecotone-business-interface and
  framework-module-scaffolding: threshold 0.8, 1 run per case. Scores are under BH13.

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

BH2 (saga handler message) — missing — packages/Ecotone/src/Modelling/Config/Routing/MissingHandlerMessage.php:30
      — the brief claims that aggregate and saga handlers keep `classesToResolve` in the message. Only the aggregate
      case is tested: UnregisteredHandlerMessageTest::test_command_handled_by_an_aggregate_missing_from_the_bootstrap_names_the_class_to_resolve.
      The saga case holds only because `Saga extends Aggregate` (`Api/Attribute/Saga.php:13`) and the check uses
      `IS_INSTANCEOF`, so no test proves it. If a later change stopped `Saga` extending `Aggregate`, a developer
      with an unregistered saga would be told to "pass an instance" of a class Ecotone builds itself. Add a
      `#[Saga]` fixture next to ShippingDepot, and a test that asserts its message names `classesToResolve`. — skill:
      framework-testing, framework-error-handling
BH12 (UnregisteredHandlerMessageTest) — missing — packages/Ecotone/tests/Lite/Test/UnregisteredHandlerMessageTest.php:26,68,82
      — the new aggregate test (`:60`) and the two tests whose expected message was reworded (`:18`, `:74`) assert
      `expectException(DestinationResolutionException::class)`. That is `Ecotone\Messaging\Handler\DestinationResolutionException`
      from `packages/Ecotone/src`, an internal class an application never imports. Drop the `expectException`
      lines and keep the `expectExceptionMessage` assertions, as 39abb2456 already did in
      ServicePassedToBootstrapTest. The import at `:8` and the same line in the untouched query test (`:54`) go
      with it. — skill: framework-testing
BH12 (correction of round 1) — round 1 called `DestinationResolutionException` the exception an application gets
      and said ServicePassedToBootstrapTest used no `src/` class. That was wrong: the class lives in `src/`. 39abb2456
      has since removed it from ServicePassedToBootstrapTest, so that file is now covered (see below).

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
BH2 (missing-handler message names passing the instance) — covered —
      UnregisteredHandlerMessageTest::test_command_handled_by_a_class_missing_from_the_bootstrap_names_the_class_to_register,
      ::test_command_without_any_handler_says_what_to_add and
      ServicePassedToBootstrapTest::test_service_passed_under_another_name_is_only_a_dependency. All three assert the
      new message text. Red proof from the implementer, before MissingHandlerMessage was edited:
      'Tests: 9, Assertions: 14, Failures: 3', exactly these three. Now OK; I re-ran it inside the Lite suite.
BH2 (aggregate handler message keeps classesToResolve) — covered —
      UnregisteredHandlerMessageTest::test_command_handled_by_an_aggregate_missing_from_the_bootstrap_names_the_class_to_resolve.
      It passes both before and after the change: the aggregate message is the old text, so the test guards the
      new branch at `MissingHandlerMessage.php:30` from sending aggregates down the "pass an instance" path.
BH12 (ServicePassedToBootstrapTest) — covered — it uses EcotoneLite, FlowTestSupport, the buses, an inline anonymous
      handler and `expectExceptionMessage` only. Since 39abb2456 it has no `src/` exception class, no reflection
      and no SQL. The ShippingDepot fixture uses only Api attributes. The three PdoEventSourcing tests change only
      which services they pass.
BH13 — covered — the case scores of `bin/test-agent-skills`, all at or above the 0.8 threshold:
      ecotone-testing/ticket-lifecycle 1.00 (its changed rule-1 grader passes, 3/3 judges),
      ecotone-asynchronous/background-notifications 0.83, ecotone-event-sourcing/order-read-model 1.00,
      ecotone-distribution/order-to-inventory 1.00, ecotone-event-sourcing/ticket-projection 1.00,
      ecotone-interceptors/cross-cutting-interceptors 1.00, ecotone-metadata/request-context-headers 1.00,
      ecotone-resiliency/flaky-payment-provider 1.00, ecotone-testing/execution-recording 1.00,
      framework-module-scaffolding/new-package 1.00. One grader failed:
      background-notifications rule-5 (ecotone-asynchronous rule 5, FAIL 3/3 judges). The generated test passed
      the handler as an instance, as the changed skill now teaches, ran `run()`, asserted nothing was sent before
      it, and used `advanceTimeBy()`. It failed only because it registered no in-memory channel in
      `extensionObjects:`. That is the part of rule 5 the diff does not touch. The one changed rule, ecotone-testing
      rule 1, has its grader `api/evals/ecotone-testing/ticket-lifecycle/graders/rule-1.md`, updated and passing.
      The other changed skill files are reference examples and change no rule.
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
      foreign key stays unregistered, as before. Since 39abb2456 the missing-handler message (raised at send time,
      not at bootstrap) names passing an instance, and BH2 above decides the tests asserting it.
BH11 — not-apply — nothing depends on time.
BH15 to BH21 — not-apply — `withClassesOfProvidedServices()` is a pure static function run once at bootstrap. It adds
      no service state, no wait, no I/O, no consumer, no child-execution copy and no coroutine test file.
BH22 — not-apply — no message flow or flow feature changes. The change alters only which classes the annotation
      finder reads. Every scenario in `packages/Ecotone/src/Test/Flow/*` (Coroutine, Projection, Endpoint, Gateway)
      already lists each service it passes in `classesToResolve`, so the flow comparison has no before/after to show.
      The reworded message affects no scenario: no scenario expects a missing-handler failure.

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
- Message wording (left for the developer experience review): a developer who passed the handler under another name
  (`'ticketDesk' => $handler`) is told to "pass an instance of its class in the containerOrAvailableServices". They
  already did. The message does not say the instance must be passed without a key or under its own class name.
- `bin/test-agent-skills` wrote its results to `api/evals/results/2026-11-24T13-15-47Z/`, which is gitignored
  (`.gitignore:30`), so it adds no tracked file.
