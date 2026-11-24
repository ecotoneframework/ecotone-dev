PASSED

Developer experience review of `git diff main...HEAD` on dgafka/ecotone-lite. First pass: d5bffa12b (the feature)
and 19d745db2 (the codemod, sampled), which gave NOT PASSED: DX1. Re-review: the fix commits
`git diff 5218f2a38..HEAD` (39abb2456, d3b7666f1). In that pass I decided DX1 again and checked every other
requirement against the new commits.

## The end user's code, most common case

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    containerOrAvailableServices: [new TicketDesk()],
);

$ecotone->sendCommandWithRouting('ticket.open', 'printer is on fire');
```

What the user has to know: one class imported (`EcotoneLite`), one argument (`containerOrAvailableServices`), the
handler instance passed once. `classesToResolve` is kept for classes the user has no instance of (aggregates, sagas,
interfaces) and for services behind a `ContainerInterface`. A service meant only as a dependency is passed under a
name (`'mailer' => $mailer`).

## Requirements

DX1 — covered (re-review; was missing) — every place an end user learns the step now teaches it once and gives the
      reason for what is left:
      - the public skills pass the handler once as an instance: api/skills/ecotone-testing/SKILL.md:36-50, :288-295
        (rule 1 states the keyed-service opt-out and that `classesToResolve` is for aggregates, sagas and interfaces);
        references/usage-examples.md, references/testing-patterns.md:45 and the troubleshooting rows :90 and :92;
        ecotone-interceptors/references/testing-patterns.md:5 and :125 (the "always register in both" line is
        replaced); the resiliency, metadata, distribution and asynchronous testing patterns
      - the missing-handler message names passing the instance as the way out, and keeps `classesToResolve` for
        aggregates and sagas and for a container passed in:
        packages/Ecotone/src/Modelling/Config/Routing/MissingHandlerMessage.php:27-34 and :43. The aggregate branch
        checks `Aggregate` with IS_INSTANCEOF, and `Saga`, `EventSourcingAggregate` and `EventSourcingSaga` all
        extend `Aggregate`, so sagas get the classesToResolve advice too.
      - proved by UnregisteredHandlerMessageTest::test_command_handled_by_an_aggregate_missing_from_the_bootstrap_names_the_class_to_resolve,
        the updated messages in UnregisteredHandlerMessageTest, and
        ServicePassedToBootstrapTest::test_service_passed_under_another_name_is_only_a_dependency, which now
        asserts the message
      - upgrade-2.0.md:4356-4366 gives the Before/Now/How to adapt entry, including the keyed opt-out and the
        newly registered attributed services
DX2 — covered — the handler is switched on by the attributes it already carries and by being passed once, with no
      registry, base class or interface (packages/Ecotone/Api/Lite/EcotoneLite.php:178, :299-312;
      ServicePassedToBootstrapTest::test_handler_passed_as_a_service_is_registered_without_listing_its_class). The
      new commits do not change this.
DX3 — covered — the class is stated once (EcotoneLite.php:305-308). Commit e3d6b319d (formerly 19d745db2) removes
      the duplicated entries in the test suites, and d3b7666f1 removes them from the public skill examples and from
      the upgrade-2.0.md converter note (:4354).
DX6 — default: a service passed without a key or under its own class name is registered — covered — it agrees
      with how `containerOrAvailableServices` already treats a key as the service id, and with Symfony and Laravel,
      where a service is named once. ServicePassedToBootstrapTest covers both bootstrap() and
      bootstrapFlowTesting(). The opt-out is now stated where users look: ecotone-testing rule 1 (SKILL.md:290-291)
      and upgrade-2.0.md:4361.
DX9 — covered — the new commits add no name an end user reads. The message reuses the existing parameter names
      `containerOrAvailableServices` and `classesToResolve`.
DX4, DX5 — not-apply — no option is added or changed: neither bootstrap() nor bootstrapFlowTesting() gains a
      parameter, also after the fix commits
DX7, DX8 — not-apply — the changed default affects which handlers are registered, not failure handling, delivery,
      transactions or data safety, and no less safe behaviour is offered
DX10 — not-apply — the change adds no name

## Not on the list

- The "No Command Handler is registered" message (MissingHandlerMessage.php:43) says "pass an instance of its
  class in the containerOrAvailableServices". It is also the message a user gets after passing the instance
  under a name (`['ticketDesk' => $desk]`, the case asserted in ServicePassedToBootstrapTest). That user did pass
  an instance, so "pass an instance of its class **without a key**" would tell them what to change. The rule is
  already stated in ecotone-testing rule 1 and upgrade-2.0.md, so this does not change the result.
- api/skills/ecotone-interceptors/SKILL.md:225, the index line for its testing-patterns reference, still
  describes it as "registering interceptors with `classesToResolve`". The reference itself now teaches the
  instance form, so this is only a stale summary.
- Known and left as is, per the coordinator: test helper wrappers (`$this->bootstrapFlowTesting`) and lists with
  comments or built from constants were not swept by the codemod, and positional `[]` placeholders stay. Neither
  is end-user surface.
