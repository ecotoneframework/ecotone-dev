# Pre-push review findings: message gathering, commit 2b08e6d37

Three independent read-only reviews (guidelines, breaking changes, behaviour). No BLOCKING finding in any of them.
Fix every item test-first where it changes behaviour. Items marked PLAUSIBLE must be reproduced by a test first; if
the test cannot reproduce it, report that instead of changing code.

## Behaviour
- M1 (should fix). `DbalGatheredGroupStore` wraps the `whenOpened` / `whenComplete` / `whenReleased` callbacks in the
  `try` that calls `MissingTableFailure::explained`, so a failure of the user's gathered handler or of the trigger
  send (for example a missing table of their own, or a missing enqueue table) is rewritten as "gathering tables
  missing" and the real exception is lost. Translate only failures of the store's own statements; callback
  exceptions pass through untouched. Test: a gathered handler throwing a DBAL exception of its own surfaces that
  exception.
- M2 (should fix, PLAUSIBLE). A split running inside a DBAL transaction with a non-transactional part or gather
  channel (AMQP, SQS, Redis) publishes parts before the group row commits; a fast report finds no group and gets
  `GatheredGroupNotFound`, whose message blames retention only. Approved handling: keep refusing (normal retry and
  dead letter of that report apply), but the exception names both causes (retention cleanup, or the split's
  transaction not committed yet) and the ways out (send the split through an outbox / combined channel so parts are
  published with the commit; replay the report). Add this to the adoption guide. Add a test.
- M3 (should fix). Missing or weak tests: conflict re-send on a non-transactional channel; discard racing a part
  and racing a release (interleaved); `releaseNow` called twice on an open group runs the handler once; timeout
  trigger arriving after ordinary completion while the group is still retained; nested groups beyond the happy path
  (inner timeout, inner gathered handler failure and replay, empty inner split); DBAL store together with a DBAL
  channel and message transaction outside the four race tests.
- M4 (minor, PLAUSIBLE). Timeout and empty-split triggers are built from the split message and inherit its delivery
  headers; a time to live shorter than the timeout would expire the trigger. Triggers must not carry the split
  message's time to live, and the empty-split trigger must not carry its delivery delay.
- M5 (minor). A message reaching a gathered handler without a group id (for example `GatheredParts` sent directly
  through the CommandBus) fails with a generic missing-header error. Refuse with a message naming the ways out.
- M6 (minor). A redelivered completing report or empty-split trigger increments the late-part count. Do not count
  it when the part row already exists or the size is 0.

## Guidelines
- M7 (should fix, error-handling rule 1). The licence refusal in `GatheringModule` names only the EcotoneLite
  argument. Reuse the existing wording that names the Symfony, Laravel and Tempest configuration, the EcotoneLite
  argument and the trial; update the asserting test.
- M8 (should fix, storage rule 16 / D2). No test proves the missing-table message. Add one that boots without the
  gathering tables, sends a split and asserts the instructions; add the row to the spec's claim table.
- M9 (should fix, public-api rule 6a). One concept, two names: `GatheredReleaseReason::RELEASED_BY_HAND` versus
  "manual release" everywhere else. Rename the enum case to `MANUAL_RELEASE = 'manual_release'`.
- M10 (minor). `GatheredGroups` listing returns a bare `array` and open groups lack the `state` key. Return `state`
  from both stores for both listings and add an array-shape docblock; drop the patches in Pulse and the console.
- M11 (minor, service-design rule 4). `releaseNow()` returns the constant text 'release requested'. Return `void`;
  console and Pulse supply the "release requested" text.
- M12 (minor, service-design rule 18). Named arguments for calls with three or more arguments in the new code.
- M13 (minor). Register `DbalGatheringModule` in `ModuleClassList` the way its neighbours are registered.
- M14 (minor). Style: inline fully qualified names, method declared above the constructor in `SplitterBuilder`,
  unsorted imports and spacing in the Pulse controller and actions, missing blank line in the conformance test.
- M15 (minor, storage rule 9). 14 tests in the core `GatheringTest` repeat, under the same names, cases the shared
  suite already runs against the in-memory store. Follow how existing shared suites avoid this duplication; if the
  core package would lose its only in-memory coverage, report that instead of deleting.

## Breaking changes
- M16 (should fix). The Pulse Gathering tab shows "No gathering groups reported" for a service that does not report
  gathering at all (feature unused, older Ecotone, or no snapshot yet). Show a distinct message for a service that
  does not report gathering, and handle the no-snapshot case as the projections page does.
- M17 (minor). Every splitter registers container ids `…<endpointId>`, `…<endpointId>.core` and
  `…<endpointId>.enterprise`; splitters named `x` and `x.core` collide at boot. Use ids that cannot nest.
- M18 (minor). The upgrade note omits the reserved names (`ecotone_gathering_*` headers, `ecotone:gathering:*`
  commands, `ecotone.gathering.*` and `ecotone.pulse.gathering.*` channels) and that with an Enterprise licence a
  plain splitter refuses a message carrying a group header.
- M19 (minor). The gathering table manager is fixed to the default DBAL connection while other optional tables take
  a configurable connection. Align it if the existing configuration pattern makes that small; otherwise state the
  limitation in the adoption guide and report it.

## Maintainer decisions during fixes

- M13: retain the literal module class string. Referencing the optional DBAL module with `::class` adds a
  `class.notFound` error to the standalone Ecotone package analysis. Neighbouring optional modules have existing
  baseline entries; adding another would grow the baseline, contrary to framework-dev-environment E4.
  The maintainer approved keeping the string on 2026-11-12.
- M15: retain the core tests. The shared suite belongs to the separate DBAL package and is absent from a
  standalone Ecotone installation; deleting the overlap would remove that package's in-memory coverage.
  The maintainer agreed on 2026-11-12.
- M19: document the default-connection limitation. The table manager and store share the default cached DBAL
  connection; selecting another requires a new configuration API and transaction wiring, rather than a small
  table-manager alignment. The adoption guide states the supported scope.
