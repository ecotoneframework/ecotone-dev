NOT PASSED: DX1

Developer experience review of `git diff main...HEAD` on dgafka/ecotone-lite (d5bffa12b feature, 19d745db2 codemod,
sampled).

## The end user's code, most common case

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    containerOrAvailableServices: [new TicketDesk()],
);

$ecotone->sendCommandWithRouting('ticket.open', 'printer is on fire');
```

What the user has to know: one class imported (`EcotoneLite`), one argument (`containerOrAvailableServices`), the
handler instance passed once. Before the change the same class also had to be listed in `classesToResolve`; that
second statement of the same fact is gone. A service meant only as a dependency is passed under a key that is not
its class name (`['ticketDesk' => $desk]`), and a PSR `ContainerInterface` still needs `classesToResolve`, because
it cannot be enumerated.

## Requirements

DX1 — missing — api/skills/ecotone-interceptors/references/testing-patterns.md:5 and :128,
      api/skills/ecotone-testing/references/testing-patterns.md:23 and :91-93,
      api/skills/ecotone-testing/references/usage-examples.md:19, :42, :67, :138 (and the same `classesToResolve:
      [$handler::class]` + `[$handler]` pair in ecotone-resiliency, ecotone-metadata, ecotone-distribution,
      ecotone-asynchronous testing-patterns), packages/Ecotone/src/Modelling/Config/Routing/MissingHandlerMessage.php:24
      and :33 — the code dropped the step, but what the end user reads still makes them take it: the public skills
      teach listing the passed handler in `classesToResolve` as well ("Always register interceptor classes in both
      `classesToResolve` ... and `containerOrAvailableServices`"), the troubleshooting table names "Handler class not in
      `classesToResolve`" as the cause, and the "not registered in this Ecotone Lite bootstrap" message offers only
      `classesToResolve` or `namespaces` as the way out, never the shortest one — so a user (or their agent) keeps
      writing a step that no longer has a reason — the simpler form: examples pass the instance once
      (`bootstrapFlowTesting(containerOrAvailableServices: [$handler])`), keep `classesToResolve` only for classes
      not passed as instances (aggregates, projections built by the framework, gateways, services under another key
      or behind a `ContainerInterface`), and let the message name "pass an instance of {$handlerClass} in
      containerOrAvailableServices" as one of the ways out — skill: framework-service-design, framework-error-handling
      (message), framework-agent-tooling (api/skills edits)
DX2 — covered — the handler is switched on by the attributes it already carries and by being passed once;
      no registry, base class or interface; packages/Ecotone/Api/Lite/EcotoneLite.php:178 and :299-312,
      ServicePassedToBootstrapTest::test_handler_passed_as_a_service_is_registered_without_listing_its_class
DX3 — covered — the class is now stated once instead of in both `classesToResolve` and
      `containerOrAvailableServices` (EcotoneLite.php:305-308); commit 19d745db2 removes the 1,318 duplicated entries
      in the test suites; sampled hunks (e.g. `bootstrapFlowTesting([$service::class], [$service], ...)` →
      `bootstrapFlowTesting([], [$service], ...)`) keep only the classes not passed as instances
DX6 — default: a service passed without a key or under its own class name is registered — covered — it agrees
      with how the same `containerOrAvailableServices` array already treats a service by key (the key is its service
      id, the class name is the natural id), and with Symfony and Laravel where the user names a service once and
      Ecotone reads its attributes; ServicePassedToBootstrapTest covers both bootstrap() and bootstrapFlowTesting()
DX9 — covered — no new name an end user reads; the private `withClassesOfProvidedServices` (EcotoneLite.php:299)
      reads without the implementation
DX4, DX5 — not-apply — no option is added or changed: neither bootstrap() nor bootstrapFlowTesting() gains a
      parameter
DX7, DX8 — not-apply — the changed default touches which handlers are registered, not failure handling, delivery,
      transactions or data safety, and no less safe behaviour is offered
DX10 — not-apply — the change adds no name

## Not on the list

- The opt-out is implicit. Passing a service under a key other than its class name now means "a dependency, do
  not read its attributes"; passing it positionally means "register it". That is the right default, but the rule
  is only in the commit message. A positional object with attributes the user did not mean to activate (a
  `#[Converter]`, an interceptor, a second handler for the same routing key) is now registered. The three
  PdoEventSourcing tagging tests show it: they had to stop passing handlers they had passed before. The public
  testing skill should state the rule in one line next to the DX1 fix.
- `ServicePassedToBootstrapTest::test_service_passed_under_another_name_is_only_a_dependency` asserts only
  `DestinationResolutionException`. It does not assert that the message tells the user how to register the
  service. That ties in with the message part of DX1.
