PASSED

# Guidelines review: EcotoneLite resolves the class of a passed service (round 3)

Round 3 re-decides GL43 and GL46, missing in round 2, and the requirements touched by `b48d98a8c..HEAD`. That range
is bbc95d50f `fix(lite): the missing handler message says to pass the instance without a key`: `MissingHandlerMessage`
says "without a key" in both service branches; `UnregisteredHandlerMessageTest` asserts messages only, with no `src/`
exception class, and gains a `#[Saga]` case with a new fixture `ShipmentTracking`; `ServicePassedToBootstrapTest`
gets the new text; the earlier `upgrade-2.0.md` entry is updated; and one index line in
`api/skills/ecotone-interceptors/SKILL.md` changes. Every changed file was read in full.

The round-2 heading follows unchanged, except the lines bbc95d50f touches.

# Round 2

Round 1 reviewed `main...HEAD` at d5bffa12b and 19d745db2 and ended `NOT PASSED: GL1, GL15, GL43, GL50`. Round 2
re-decides those four and every requirement the fix range `5218f2a38..HEAD` touches. The other requirements keep
their round-1 status, which is restated below.

## What changed and why

Range `main...HEAD`:
- d5bffa12b `feat(lite): a service passed to EcotoneLite is registered without listing its class`
- e3d6b319d `test(lite): classes passed as services are no longer repeated in classesToResolve` (19d745db2 reworded,
  content identical)
- 5218f2a38 `docs(reviews): …`, the decided lists
- 39abb2456 `fix(lite): the missing handler message names passing the handler instance`
- d3b7666f1 `docs(skills): testing examples pass a handler once, as an instance`

Purpose: a handler passed as an instance in `containerOrAvailableServices` is now registered without repeating its
class in `classesToResolve`. A service under another name stays a plain dependency, and a `ContainerInterface` is not
enumerated.

Files of the fix range, read in full:
- `packages/Ecotone/src/Modelling/Config/Routing/MissingHandlerMessage.php`: the unregistered-handler message now
  names passing the instance. An aggregate or saga (`#[Aggregate]` with `IS_INSTANCEOF`, which `Saga` extends) keeps
  `classesToResolve`. The no-handler message names both.
- `packages/Ecotone/tests/Lite/Test/UnregisteredHandlerMessageTest.php`: two messages updated and a new aggregate case.
- `packages/Ecotone/tests/Lite/Fixtures/UnregisteredHandler/ShippingDepot.php` (new): an aggregate fixture.
- `packages/Ecotone/tests/Lite/Test/ServicePassedToBootstrapTest.php`: the test asserts the message.
- `packages/PdoEventSourcing/tests/Integration/Tagging/DynamicConsistencyBoundaryDisabledDbalTest.php`: `strict: true`.
- `upgrade-2.0.md`: the new auto-resolve entry, and the JMS converter entry adapted.
- `api/skills/ecotone-testing/SKILL.md`: rule 1 rewritten.
- `api/evals/ecotone-testing/ticket-lifecycle/graders/rule-1.md`
- Nine `testing-patterns.md` / `usage-examples.md` / `creating-a-module.md` example hunks that drop the repeated class.

Round-1 inventory (unchanged): `packages/Ecotone/Api/Lite/EcotoneLite.php` `withClassesOfProvidedServices()`, the
new `ServicePassedToBootstrapTest`, three adapted PdoEventSourcing tagging tests, and the codemod (sampled).

Skills whose requirements apply: `framework-testing`, `framework-service-design`, `framework-public-api`,
`framework-error-handling` (the exception message changed), `framework-design-and-briefs` 19, `framework-agent-tooling`
(a public skill rule and its grader changed), `framework-dev-environment` E4, `framework-orientation` O2,
`framework-pull-request` P1.

## Decisions

GL43 — covered — upgrade-2.0.md:4356-4366, the auto-resolve entry, has Before / Now / How to adapt. The earlier entry
      (`:4553-4564`, "… names the class to register") now quotes the current message (`Pass an instance of …, without a
      key, in the containerOrAvailableServices …`) and says an aggregate or saga handler is told to use
      `classesToResolve` (bbc95d50f)
GL46 — covered — the changed rule, `ecotone-testing` rule 1, has its updated grader
      `api/evals/ecotone-testing/ticket-lifecycle/graders/rule-1.md`. Its eval cases were run: the behaviour report
      (`docs/reviews/2026-11-24-lite-service-auto-resolve-review-list-1-behaviour.md:114-125`, BH13) records
      `bin/test-agent-skills` for the 9 changed skills, with all 10 cases at or above 0.8 and ticket-lifecycle at 1.00.
      bbc95d50f changes only one reference-index line of `ecotone-interceptors/SKILL.md`, not a rule; the behaviour
      session re-runs that skill's cases for this round.

GL1 — covered — ServicePassedToBootstrapTest.php:62 and every case of UnregisteredHandlerMessageTest assert only the
      user-facing text with `expectExceptionMessage(...)`. No `src/` class is imported; bbc95d50f also removed the
      pre-existing `DestinationResolutionException` expectations.
GL4 — covered — the new methods are snake_case and have no comments. The new named fixtures `ShippingDepot` and
      `ShipmentTracking` (bbc95d50f) sit in
      `tests/Lite/Fixtures/UnregisteredHandler/`, which only `UnregisteredHandlerMessageTest` uses. The behaviour under
      test forces a file: `UnregisteredHandlerFinder` (`UnregisteredHandlerFinder.php:44-75`) finds handlers by scanning
      PSR-4 directories, which an inline anonymous class cannot satisfy.
GL12 — covered — MissingHandlerMessage.php:30 reflects on a class that is by definition not registered, so
      `InterfaceToCallRegistry` cannot answer for it. This runs on the same failure-only path where
      `UnregisteredHandlerFinder` already reflects.
GL14 — covered — ShippingDepot.php and ShipmentTracking.php: `declare(strict_types=1)` and `final class`; both carry
      `licence Apache-2.0`
GL15 — covered — DynamicConsistencyBoundaryDisabledDbalTest.php:131 `in_array(..., $classesToResolve, strict: true)`.
      The other new call, `getAttributes(Aggregate::class, ReflectionAttribute::IS_INSTANCEOF)`, has two arguments.
GL16 — covered — no comments or prose docblocks are added in MissingHandlerMessage.php or the tests
GL17 — covered — the message uses the parameter names verbatim (`containerOrAvailableServices`, `classesToResolve`), as
      `EcotoneLite` and the skills do
GL23 — covered — each branch of MissingHandlerMessage says what is wrong (not registered, or no handler), why (this
      Ecotone Lite bootstrap), and every way out:
      - service handler: the instance, `classesToResolve` for a container-provided service, `namespaces`; asserted by
        `UnregisteredHandlerMessageTest:27`
      - aggregate: `classesToResolve` or `namespaces`; asserted by
        `test_command_handled_by_an_aggregate_missing_from_the_bootstrap_names_the_class_to_resolve`
      - saga: the same as the aggregate; asserted by
        `test_command_handled_by_a_saga_missing_from_the_bootstrap_names_the_class_to_resolve`
      - "without a key" in both service branches (bbc95d50f): asserted at UnregisteredHandlerMessageTest:25,
        :90 and ServicePassedToBootstrapTest:62
      - no handler: the attribute and the instance, or `classesToResolve` for an aggregate, a saga or a container;
        asserted by `test_command_without_any_handler_says_what_to_add` and
        `ServicePassedToBootstrapTest::test_service_passed_under_another_name_is_only_a_dependency`
GL45 — covered — the changed rule sits in `ecotone-testing` rule 1, the public skill whose *Applies when* covers how a
      flow test boots
GL44 — covered — the changed rule needs no deterministic check: repeating a passed class in `classesToResolve` is
      allowed and harmless (the grader says so), so there is nothing to refuse
GL47 — covered — `prompt.md` of `ticket-lifecycle` is unchanged; the grader is a PASS/FAIL rubric and quotes no rule
GL49 — covered — `Ecotone\Api\Attribute\Aggregate`, `Saga` (extends `Aggregate`), `Identifier` and `CommandHandler` exist;
      the skill examples name only `EcotoneLite::bootstrapFlowTesting()` parameters that exist
GL50 — covered — `feat(lite):`, `test(lite):`, `docs(reviews):`, `fix(lite):` ×2, `docs(skills):`; every message names
      the defect, its cause and the change
GL51 — covered — MissingHandlerMessage.php:30 is a branch on the handler's class. It only picks the text of a refusal
      that is thrown (`CommandBusRouteSelector`/`QueryBusRouteSelector`) and never selects how a message is handled.
      The round-1 branches at EcotoneLite.php:301 and :306 read only the component's own input contract.
GL2 — covered — `ServicePassedToBootstrapTest` proves both entry points, `bootstrapFlowTesting()` and `bootstrap()`
GL9 — covered — `withClassesOfProvidedServices()` and `forFlowTesting()` only read and return
GL20 — covered — no public signature changed and nothing was added under `Api/`
GL27 (unkeyed, own class name, other name) — covered — the three `ServicePassedToBootstrapTest` cases
GL27 (class also listed in classesToResolve) — covered — CreateAggregateByEventSourcingAggregateTest.php:38-39;
      `array_unique` at EcotoneLite.php:311
GL27 (ContainerInterface) — covered — the path is unchanged (EcotoneLite.php:301-303), and
      `MessagingTestSupportFrameworkTest::test_add_gateways_to_container` still covers it
GL48 — covered — no `phpstan-baseline.neon` changed in the range

GL3, GL5, GL6 — not-apply — the new and changed tests are synchronous and have no consumer, channel or time dependency
GL7, GL8, GL10 — not-apply — no constructor dependency, service property, static or interface added
GL11, GL13 — not-apply — no new configuration form; the existing `classesToResolve` argument is extended
GL18, GL19 — not-apply — no `Api/` value class and no new class an application uses
GL21, GL22 — not-apply — no console option and no message header
GL24, GL25, GL26 — not-apply — no new way to configure something wrongly, no new exception carrying build-time context
      (the message text of an existing refusal changed), no new compile-time check
GL28 to GL36 — not-apply — no storage, connection or transaction
GL37 — not-apply — no Enterprise behaviour and no new file under `src`/`Api/`; the new test files carry
      `licence Apache-2.0`
GL38, GL39, GL40 — not-apply — no module or package added or tested
GL41, GL42 — not-apply — no design or spec in the range

## Not on the list

- The codemod did not sweep test helper wrappers (`$this->bootstrapFlowTesting`) or lists that carry comments or are
  built from constants, and the 185 positional `[]` placeholders stay. The coordinator states this is known and left as
  is; it is harmless because `array_unique` drops the duplicates.
- In `MissingHandlerMessage`, the aggregate check (`:30`) builds a fresh `ReflectionClass` of a class found by file
  scan. If that class cannot be autoloaded, it throws inside the message builder, unlike the finder, which swallows
  `Throwable`. The finder only returns classes it has already reflected, so this does not happen today.
