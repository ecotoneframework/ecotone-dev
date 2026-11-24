NOT PASSED: BC6 (missing-handler message)

Re-review of `git diff 5218f2a38..HEAD` (39abb2456 fix(lite), d3b7666f1 docs(skills); e3d6b319d is the reworded,
content-identical codemod). First review covered `main...HEAD` at d5bffa12b + 19d745db2. Current version: latest tag
`2.0.0-beta.10`, branch alias `dev-main` → `2.0.x-dev`. Target release: 2.0.0 (or a further 2.0 pre-release), a major
over 1.x.

BC6 (missing-handler message) — missing — upgrade-2.0.md:4553-4561 — 39abb2456 changes the flow-test
      `DestinationResolutionException` text in packages/Ecotone/src/Modelling/Config/Routing/MissingHandlerMessage.php:27-42:
      a plain service handler now gets "Pass an instance of … in the containerOrAvailableServices …, add … to its
      classesToResolve when the service comes from a container passed there, or load its namespace …", and only an
      aggregate or saga (Saga extends Aggregate, matched by IS_INSTANCEOF) keeps "Add … to the classesToResolve". The
      existing 2.0 entry for this message still quotes the old text for the plain-service example
      `App\Shipping\ShippingSlotReservationHandler` ("Add … to the classesToResolve of EcotoneLite::bootstrapFlowTesting(), or
      load its namespace …"), so its "Now" no longer matches what a user upgrading from 1.x sees. The exception class
      is unchanged, so nothing that catches it breaks; only the note is wrong. Fix: quote the new plain-service text in
      that entry and add one clause saying an aggregate or saga handler is told to add its class to
      `classesToResolve` (the "no handler" variant now names passing an instance as well) — skill:
      framework-design-and-briefs

BC6 (service passed to EcotoneLite is registered) — covered — upgrade-2.0.md:4356-4366 "A service passed to
      `EcotoneLite` is registered without listing its class", with Before (attributes read only when the class was
      also in `classesToResolve`), Now (no key or own class name is resolved; another name and a `ContainerInterface`
      are not) and How to adapt (drop duplicates; stop passing a service, or pass it under a name, where it must not
      be registered). It names the failure an upgrading test hits (second handler for one command, handler needing
      unregistered configuration). The converter advice at upgrade-2.0.md:4353-4355 was updated to "an instance in
      `containerOrAvailableServices`"
BC3 — covered — under `Api/` the branch still only adds the private `EcotoneLite::withClassesOfProvidedServices`
      (packages/Ecotone/Api/Lite/EcotoneLite.php:299); 39abb2456 touches only `src/` (MissingHandlerMessage, a final
      internal class) and tests; the catchable exception class stays `DestinationResolutionException`
      (UnregisteredHandlerMessageTest still expects it); no public signature, attribute, gateway or configuration
      object changes
BC1, BC2 — not-apply — nothing written to a table, queue, outbox, dead letter or event store changes; bootstrap class
      discovery and an exception text only
BC4 — not-apply — no default parameter value changes (`classesToResolve` still defaults to `[]`)
BC5 — not-apply — no console command name or option is touched
BC7 — not-apply — the change ships in the 2.0 major, so the new resolution is allowed as a default (its note is BC6)
BC8 — not-apply — nothing a mixed-version deployment shares on a queue or table changes

Notes (not requirements of this section): d3b7666f1 changes only public skills, the ecotone-testing rule 1 and its
grader (documentation, no runtime surface). The DynamicConsistencyBoundaryDisabledDbalTest hunk switches `in_array` to
strict, test-only. Classes stay deduplicated (`array_unique` in EcotoneLite and FileSystemAnnotationFinder.php:148).
The unswept helper wrappers, commented or constant-built lists and `[]` placeholders are known and left as is; they
keep working because a duplicate entry is harmless.
