NOT PASSED: GL43, GL46

# Guidelines review: EcotoneLite resolves the class of a passed service (round 2)

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

GL43 — missing — upgrade-2.0.md:4553-4560 — the earlier entry "A command or query sent to a handler that is not
      registered in a flow test names the class to register" still quotes the message as `… Add
      App\Shipping\ShippingSlotReservationHandler to the classesToResolve of EcotoneLite::bootstrapFlowTesting(), or
      load its namespace …`. 39abb2456 changed that text: for a service handler, the message now says `Pass an
      instance of … in the containerOrAvailableServices …`, so the upgrade note quotes a message a 2.0 user never sees.
      Fix: update the quoted message in that entry, both the service text and the no-handler sentence ("says which
      attribute to add"), and say that an aggregate or saga keeps the `classesToResolve` advice. The new auto-resolve
      entry itself (`:4356-4366`) is covered: it has Before / Now / How to adapt — skill: framework-design-and-briefs 19
GL46 — missing — api/skills/ecotone-testing/SKILL.md (rule 1) — rule 1 and its grader
      `api/evals/ecotone-testing/ticket-lifecycle/graders/rule-1.md` were changed, but no eval run or score is
      reported: neither commit message mentions one and the range has no result file — run
      `bin/test-agent-skills ecotone-testing`, as rule A4 places it in the behaviour review session, and report the
      scores — skill: framework-agent-tooling A4

GL1 — covered — ServicePassedToBootstrapTest.php no longer imports a `src/` class. `:62` asserts the user-facing text
      with `expectExceptionMessage(...)`. (`UnregisteredHandlerMessageTest`'s pre-existing `DestinationResolutionException`
      expectations are untouched code, and so are not counted; the new aggregate case repeats that existing pattern.)
GL4 — covered — the new methods are snake_case and have no comments. The new named fixture `ShippingDepot` sits in
      `tests/Lite/Fixtures/UnregisteredHandler/`, which only `UnregisteredHandlerMessageTest` uses. The behaviour under
      test forces a file: `UnregisteredHandlerFinder` (`UnregisteredHandlerFinder.php:44-75`) finds handlers by scanning
      PSR-4 directories, which an inline anonymous class cannot satisfy.
GL12 — covered — MissingHandlerMessage.php:30 reflects on a class that is by definition not registered, so
      `InterfaceToCallRegistry` cannot answer for it. This runs on the same failure-only path where
      `UnregisteredHandlerFinder` already reflects.
GL14 — covered — ShippingDepot.php: `declare(strict_types=1)` and `final class`; it carries `licence Apache-2.0`
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
      - no handler: the attribute and the instance, or `classesToResolve` for an aggregate, a saga or a container;
        asserted by `test_command_without_any_handler_says_what_to_add` and
        `ServicePassedToBootstrapTest::test_service_passed_under_another_name_is_only_a_dependency`
GL45 — covered — the changed rule sits in `ecotone-testing` rule 1, the public skill whose *Applies when* covers how a
      flow test boots
GL44 — covered — the changed rule needs no deterministic check: repeating a passed class in `classesToResolve` is
      allowed and harmless (the grader says so), so there is nothing to refuse
GL47 — covered — `prompt.md` of `ticket-lifecycle` is unchanged; the grader is a PASS/FAIL rubric and quotes no rule
GL49 — covered — `Ecotone\Api\Attribute\Aggregate`, `Identifier`, `CommandHandler` and `Saga extends Aggregate` exist;
      the skill examples name only `EcotoneLite::bootstrapFlowTesting()` parameters that exist
GL50 — covered — `feat(lite):`, `test(lite):`, `docs(reviews):`, `fix(lite):`, `docs(skills):`; every message names
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
