# Pre-push review findings: Pulse snapshot sections sent and stored only when changed, commit d157d84bc

Four independent reviews (guidelines, breaking changes, behaviour, developer experience), run as sessions in the
implementation worktree. No BLOCKING finding. The behaviour session ran the changed suites and the whole Pulse
application suite (862 tests) green on d157d84bc.

## Guidelines
- G1 (should fix). `PulseDeadLetterActions` replies partially from two return lines, replay and delete, and only
  delete has a partial-reply test. Add the same test for replay.
- G2 (should fix). The tenant-scoped branch of `PulseSnapshot::answer()` has no test: nothing sends `known` with a
  tenant header or an action under a tenant.
- G3 (nit). `PulseSnapshotResponderTest` pins a digest to 32 hexadecimal characters, which no document promises.
- G4 (should fix). `PulseSnapshot::answer()` is one long method with two loops and the "include unless known"
  logic written twice. Make it a step list with one shared helper.
- G5 (nit). `SnapshotStore::storeSections()` packs three retention rules into one boolean expression. Name them.
- G6 (nit). One concept under three names: `revision()`, `digests`, `known`. Rename or document.
- G7 (nit). A request without a `known` key, and a section name `sectionsJson()` does not know, are both accepted
  silently. Both fail in the safe direction.
- G8 (nit). `SnapshotStore.php:62` passes three positional arguments with a ternary in the middle.
- G9 (nit). `SectionedSnapshot::empty()` is never called.
- G10 (nit). Two sentences added to `upgrade-2.0.md` run past the wrap width.

## Breaking changes
- B1 (should fix). Action replies are now partial while the schema version stays 1, so a Pulse from an earlier
  beta rejects every action reply of an upgraded service with "Snapshot has no overview section", which blames
  the wrong side, and overwrites a tenant snapshot with the partial one. Bump the schema version on both sides.
- B2 (nit). `upgrade-2.0.md` says the dead letter action reply is partial but not the rebuild, backfill and
  gathering ones.
- B3 (nit). A `known` that is not a map of strings is refused, where the payload used to be ignored, so a later
  Pulse adding a field inside `known` would fail instead of falling back.

## Behaviour
- V1 (should fix). The dead letter revision is the count, the newest message id and its time, and that time is
  the message timestamp, not the failure time. Removing one dead letter while an older-stamped one arrives, or a
  replay failing again with another exception message, leaves the revision unchanged and Pulse stale.
- V2 (should fix). Multi-tenancy is untested on both sides: no `known` per tenant on the service, and no page
  read of a tenant built from stored sections after an unchanged tick.
- V3 (should fix). No red-first proof was handed over: the implementation was written before the new tests ran.
- V4 (should fix). Same as B1.
- V5 (nit). A section left out as unchanged can go missing for one tick when its stored row was deleted between
  the request and the reply. It heals on the next tick.
- V6 (nit). A page reads each section with its own query outside a transaction, so one render can mix sections
  of two replies. Read the sections of a scope in one query.
- V7 (nit). The flow graph, packages and topology are still built and hashed on every tick to compare digests.
- V8 (nit). A gathering release replies with `gathering` only although it can change a projection's status, and
  a rebuild reply leaves out channels and dead letters. The next tick covers both; say so.
- V9 (nit). The delete partial-reply test only asserts that `channels` is present.

## Developer experience
- NO USER-FACING SURFACE.
