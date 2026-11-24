NOT PASSED: GL1, GL15, GL43, GL50

# Guidelines review: EcotoneLite resolves the class of a passed service (round 1)

Range `main...HEAD`: d5bffa12b `feat(lite): a service passed to EcotoneLite is registered without listing its class`
and 19d745db2 `test: classes passed as services are no longer repeated in classesToResolve`.

## What changed and why

Purpose: a handler instance passed in `containerOrAvailableServices` was registered only if its class was also listed in
`classesToResolve`. If the class was left out, the test failed with "handler is not registered". Now `bootstrap()` and
`bootstrapFlowTesting()` add the class of every service passed without a key, or under its own class name. A service
under another name stays a plain dependency, and a `ContainerInterface` is not enumerated.

Files read in full:
- `packages/Ecotone/Api/Lite/EcotoneLite.php`: the `classesToResolve:` argument (`:178`) now goes through the new
  private `withClassesOfProvidedServices()` (`:294-312`).
- `packages/Ecotone/tests/Lite/Test/ServicePassedToBootstrapTest.php` (new): four tests.
- `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelDbalTest.php`,
  `DynamicConsistencyBoundaryDisabledDbalTest.php`, `StateStoredAggregateCounterDbalTest.php`: these tests passed a
  handler in every scenario but listed its class in only some of them. They now pass the handler only in the scenarios
  where it should be registered.
- 19d745db2 (306 test files, 968+/1156−): sampled. I read the `Ecotone/tests/Modelling` hunks and the added lines that do
  more than remove an entry. All of them remove a `classesToResolve` entry whose instance is passed without a key, or
  reflow a list after that removal. No phpstan baseline, `src/`, `Api/`, skill or doc file changed.

Skills whose requirements apply: `framework-testing` (new and changed tests), `framework-service-design` (a new method
and an added branch in the bootstrap wiring, a new call in a test), `framework-public-api` (a change in an `Api/`
class), `framework-error-handling` 1d (the meaning of an existing bootstrap parameter is widened),
`framework-design-and-briefs` 19 (a behaviour a 1.x application observes), `framework-dev-environment` E4,
`framework-orientation` O2, `framework-pull-request` P1.

## Decisions

GL1 — missing — packages/Ecotone/tests/Lite/Test/ServicePassedToBootstrapTest.php:10,63 — the test imports and expects
      `Ecotone\Messaging\Handler\DestinationResolutionException`, a `src/` class, so rewriting `src/` would break it —
      instead, assert the error the user sees: drop the import and `expectException()`, and use
      `$this->expectExceptionMessage('No Command Handler is registered for it in this Ecotone Lite bootstrap')`, which is
      also the text the reporter got — skill: framework-testing 10
GL15 — missing — packages/PdoEventSourcing/tests/Integration/Tagging/DynamicConsistencyBoundaryDisabledDbalTest.php:131 —
      the added `in_array(HandlerInjectingRenamesForDisabledBoundaryDbalTest::class, $classesToResolve, true)` passes three
      positional arguments — name the flag: `in_array(..., $classesToResolve, strict: true)` — skill:
      framework-service-design 18. Deterministic-check candidate: a phpstan/regex rule could flag a positional `true` as
      the third argument of `in_array` (rule A1). Existing code calls `in_array(..., true)` positionally 156 times in
      `src`/`Api`; that is pre-existing and not counted here.
GL43 — missing — upgrade-2.0.md — a 1.x application notices this change. Its test suites that pass a handler instance
      without a key and do not list its class now get that handler registered, so its handlers, event handlers and
      converters start running. The three adapted PdoEventSourcing tests are this exact case: they broke and had to be
      changed. The entry near `:2241`/`:4541`, where the other Ecotone Lite bootstrap notes are, says nothing about it —
      add an entry. Before: a service in `containerOrAvailableServices` was only a dependency until its class was also
      listed in `classesToResolve`. Now: a service passed without a key, or under its own class name, is registered;
      under another name it stays a dependency, and a `ContainerInterface` is not enumerated. How to adapt: pass a service
      you do not want registered under another name, or only where it should be registered; the repeated
      `classesToResolve` entries can be removed — skill: framework-design-and-briefs 19
GL50 — missing — 19d745db2 — the subject `test: classes passed as services are no longer repeated in classesToResolve`
      has no scope, but the requirement asks for `type(scope): ` — reword it to
      `test(lite): classes passed as services are no longer repeated in classesToResolve` — skill:
      framework-pull-request P1. Deterministic-check candidate (a subject regex in CI). Note: P1's own *Expected* regex
      `(\(.+\))?` makes the scope optional, while P1's title and GL50 require it; I went with the requirement text.

GL2 — covered — `ServicePassedToBootstrapTest::test_handler_passed_as_a_service_is_registered_without_listing_its_class`
      (`bootstrapFlowTesting()`) and `::test_handler_passed_as_a_service_is_registered_outside_of_flow_testing`
      (`bootstrap()`), the two entry points that reach `prepareConfiguration()`
GL4 — covered — ServicePassedToBootstrapTest.php: snake_case names that describe the behaviour, no comments or assertion
      messages, inline anonymous fixture `ticketDesk()` (`:68`); the adapted PdoEventSourcing tests add no comments
GL9 — covered — EcotoneLite.php:299 `withClassesOfProvidedServices()` is a pure read: it returns a new list and changes
      nothing (the array is passed by value)
GL12 — covered — EcotoneLite.php:306-307 reads the service's class with `get_class()` on the instance and does no
      `ReflectionClass`/`ReflectionMethod` lookup
GL14 — covered — ServicePassedToBootstrapTest.php:3 `declare(strict_types=1)`, `:17` `final class`; no new class in
      `src/` or `Api/`
GL16 — covered — EcotoneLite.php:294-298 has only type docblocks (`string[]`, `ContainerInterface|object[]`), no prose
      and no inline comments
GL17 — covered — the concept "provided service" keeps the word already used by `providedServiceIds()`
      (EcotoneLite.php:318): `withClassesOfProvidedServices()`; no synonym introduced
GL20 — covered — no public signature of `EcotoneLite` changed; the new method is private, and no plumbing class is
      added under `Api/`
GL27 (unkeyed service) — covered — `test_handler_passed_as_a_service_is_registered_without_listing_its_class`
GL27 (keyed under its own class name) — covered —
      `test_handler_passed_as_a_service_under_its_own_class_name_is_registered_without_listing_its_class`
GL27 (keyed under another name) — covered — `test_service_passed_under_another_name_is_only_a_dependency`
GL27 (class also listed in classesToResolve, the repeated registration) — covered —
      `packages/PdoEventSourcing/tests/Integration/CreateAggregateByEventSourcingAggregateTest.php:38-39` still lists
      `EventsConverter::class` and passes `new EventsConverter()`; `array_unique` at EcotoneLite.php:311
GL27 (ContainerInterface) — covered — the path is unchanged (early return at EcotoneLite.php:301-303), and
      `MessagingTestSupportFrameworkTest::test_add_gateways_to_container` still boots a PSR container together with
      `classesToResolve`. There is no test showing that a container's services are *not* registered, but that behaviour
      is pre-existing.
GL48 — covered — no `phpstan-baseline.neon` changed in the range
GL49 — covered — `Ecotone\Api\Attribute\CommandHandler`, `QueryHandler` and `Ecotone\Api\Lite\EcotoneLite` exist; the
      parameter names `containerOrAvailableServices` and `classesToResolve` match the signature
GL50 (d5bffa12b) — covered — `feat(lite): ` and a message that names the defect, its cause and the fix
GL51 — covered — EcotoneLite.php:301 and :306, the two branches added to the bootstrap wiring. Both read only the
      component's own input contract: whether the documented `ContainerInterface|array` parameter is a container, and
      the key it was given (int, or the service's own class). Neither reads a scenario, a header or the runtime
      situation. Both feed the existing `classesToResolve` path, so nothing gets a second implementation. This mirrors
      `providedServiceIds()` at `:318-329`.

GL3, GL5, GL6 — not-apply — the new and changed tests are synchronous: they have no consumer, no channel and no time
      dependency
GL7, GL8, GL10 — not-apply — no constructor dependency, no service property or static, no interface added
GL11, GL13 — not-apply — no new configuration form; the existing `classesToResolve` argument is extended and the
      `prepareConfiguration()` call keeps its shape
GL18, GL19 — not-apply — no `Api/` value class and no new class an application uses
GL21, GL22 — not-apply — no console option and no message header
GL23, GL24, GL25, GL26 — not-apply — no exception thrown or changed, no new way to configure something wrongly, no new
      check or guard
GL28 to GL36 — not-apply — the change reads and writes no storage and opens no connection or transaction
GL37 — not-apply — no Enterprise behaviour and no new file under `src`/`Api/`; the new test still carries
      `licence Apache-2.0` (ServicePassedToBootstrapTest.php:14)
GL38, GL39, GL40 — not-apply — no module or package added or tested
GL41, GL42 — not-apply — no design or spec in the range
GL44 to GL47 — not-apply — no rule, skill or eval case added or changed

## Not on the list

- The codemod is incomplete. About 100 bootstrap calls still list a class whose instance they pass without a key, for
  example `packages/Ecotone/tests/Modelling/Unit/MessageBusTest.php:277,280` and
  `packages/PdoEventSourcing/tests/Integration/CreateAggregateByEventSourcingAggregateTest.php:38,71`. These lists
  likely span lines or carry comments. It is harmless, because `array_unique` drops the duplicates, but the commit
  message says the duplicated entries "are removed". Either finish the sweep (and keep one test with the class repeated
  on purpose, for GL27) or narrow the message.
- 185 rewritten calls now start with a positional empty `classesToResolve`, for example
  `EcotoneLite::bootstrapFlowTesting([], [$orders], [$sharedChannel])`. They were positional before (pre-existing), but
  a named `containerOrAvailableServices:` with the `[]` dropped would read better.
- `MissingHandlerMessage` (`packages/Ecotone/src/Modelling/Config/Routing/MissingHandlerMessage.php:24,33`) still names
  only `classesToResolve` and `namespaces` as ways out. Passing the handler instance is now a third way out, and the
  message could name it (rule 1, `framework-error-handling`).
