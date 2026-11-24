NOT PASSED: BC6

Range: `git diff main...HEAD` (d5bffa12b feature, 19d745db2 codemod). Current version: latest tag `2.0.0-beta.10`,
branch alias `dev-main` → `2.0.x-dev`. Target release: 2.0.0 (or a further 2.0 pre-release), a major over 1.x.

BC6 — missing — packages/Ecotone/Api/Lite/EcotoneLite.php:178 (withClassesOfProvidedServices, :299) and upgrade-2.0.md
      (no entry) — an application or test upgrading from 1.x that passes an object to
      `EcotoneLite::bootstrap()` / `bootstrapFlowTesting()` in `containerOrAvailableServices` without a key (or under its
      own class name) and deliberately does not list its class in `classesToResolve` now gets that class's attributes
      registered: its `#[CommandHandler]`/`#[EventHandler]`/`#[QueryHandler]` methods start handling messages,
      `#[Converter]`s and interceptors start applying, and its attributes are validated at bootstrap, so a bootstrap that
      booted before can now throw or route differently. The change itself had to rewrite three tests that did exactly
      this (packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelDbalTest.php:262,
      DynamicConsistencyBoundaryDisabledDbalTest.php:128, StateStoredAggregateCounterDbalTest.php:258), which shows
      existing code notices it. This is a 1.x→2.0 difference, not only a beta-to-beta one — add an `upgrade-2.0.md`
      entry: **Before:** a passed service was only a container dependency; its attributes were read only when its class
      was also in `classesToResolve`. **Now:** the class of every service passed without a key or under its own class
      name is resolved; a service under another name or a `ContainerInterface` is not. **How to adapt:** pass an
      instance whose handlers must not be registered under a different service name (or not at all), and drop now
      redundant `classesToResolve` entries. While there, the existing entry at upgrade-2.0.md:4354 ("its class in
      `classesToResolve` and an instance in `containerOrAvailableServices`") becomes half redundant and can say passing
      the instance is enough — skill: framework-design-and-briefs

BC3 — covered — packages/Ecotone/Api/Lite/EcotoneLite.php: the only change under `Api/` is the call at :178 and a new
      `private static` method `withClassesOfProvidedServices` (:299); the public signatures of `bootstrap()`,
      `bootstrapFlowTesting()` and the other entry points (names, parameter names, types, defaults) are unchanged; no
      attribute, gateway, `#[ServiceContext]` object or catchable exception changes
BC1, BC2 — not-apply — nothing written to a table, queue, outbox, dead letter or event store changes; the change only
      widens the list of classes the annotation finder scans at bootstrap
BC4 — not-apply — no default parameter value changes (`classesToResolve` still defaults to `[]`); the semantic widening
      is a behaviour change, decided under BC6
BC5 — not-apply — no console command name or option is touched
BC7 — not-apply — the change ships in the 2.0 major, so a new default is allowed (its upgrade note is BC6)
BC8 — not-apply — bootstrap-time class discovery only; nothing a mixed-version deployment shares on a queue or table
      changes

Notes (not requirements of this section): the codemod 19d745db2 was sampled (EventStoreConformanceTest,
TaggedEventStoreConformanceTest, EventSourcedAggregateTestSupportFrameworkTest) — it only removes entries whose
instance is passed without a key, test-only, no public surface. Duplicate resolution is safe: classes are deduplicated
in `withClassesOfProvidedServices` (`array_unique`) and again in FileSystemAnnotationFinder.php:148, so a class found
both through `namespaces` and through a passed service is registered once.
