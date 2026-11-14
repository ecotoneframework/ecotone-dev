# Pre-push review findings: message collecting, commit c455a04e3

Four independent read-only reviews (behaviour, guidelines, breaking changes, developer experience), 2026-11-14.
No BLOCKING finding. Fix every item test-first where it changes behaviour. Items marked PLAUSIBLE must be
reproduced by a test first; if the test cannot reproduce it, report that instead of changing code.
Items marked HELD wait for the maintainer; do not start them.

## Behaviour
- C1 (should fix, high). Message identity is lost on a conflict re-send, so a message can be collected twice.
  M1 loses the version check and is re-sent as M2 (new id); M1 is then redelivered (crash between commit and
  acknowledge on a broker that acknowledges after commit, or the re-send left to a non-transactional broker and the
  transaction rolled back). Deduplication is by message id and only M2's id is recorded, so both are collected.
  Fix: the re-sent message carries the original message's identity in a framework header and `collectMessage`
  deduplicates on that header when present, else on the message id. A chain of re-sends keeps the first identity.
  Tests: conflict then redelivery of the original; conflict on a non-transactional channel.
- C2 (should fix). The maxWait timer is built from scratch and drops every header of the first message, including
  the tenant header, so under header-based multi-tenancy the timer cannot find its batch and a maxWait-only batch
  never releases. Build the timer as gathering does (from the first message, minus time to live and delivery
  delay). Test with a multi-tenant connection. The headers of the BATCH message given to the handler are HELD
  (see H3); only the timer is to be fixed now.
- C3 (should fix). Concurrency proofs are too narrow: the race sends exactly maxSize messages. Add on real
  databases: maxSize + 1 messages under concurrent consumers (one released batch of maxSize, one open batch of 1,
  nothing lost, nothing in the dead letter); a maxWait timer racing an append; a manual release racing an append.
- C4 (should fix). Failure on a maxWait or manual release is untested: handler failing then retried; the releasing
  message reaching the dead letter and being replayed. Add the tests and state in the guide what the code does
  while the releasing message waits: on the maxSize path the next message fills the batch without the
  dead-lettered one, whose replay opens a new batch; on the maxWait path the batch stays open past its deadline and
  keeps collecting until the replay; with delayed retries the retried batch may hold more messages than the first
  attempt.
- C5 (should fix). Decision 16 is weakly proved: conformance tests use an in-memory queue channel, so payloads
  never pass serialisation. Add: an object payload through a DBAL channel restored by its type header with a
  string `groupBy`; a message without a type header exposed as it arrived; the closure-typed conversion on a real
  channel.
- C6 (minor, PLAUSIBLE). Cleanup deleting the latest closed generation of a key while two consumers arrive can
  leave two open batches for one key (one inserts N+1, the other sees no rows and inserts 1); the lower one is never
  picked again. Reproduce with a test first. If real, report the reproduction and your proposed guard to the
  coordinator before implementing (candidates: cleanup keeps the latest generation of a key; or at most one open
  batch per handler and key enforced by the database).
- C7 (minor). Key normalisation is undocumented and untested: 1 and '1', true and '1', false and '' share a batch;
  an empty string is accepted. Refuse an empty-string key and a boolean key with the same refusal as null (a key
  must be a non-empty string, an integer or a stringable object); document that 1 and '1' are the same key. Tests
  for those, a very long key and keys differing only in case.
- C8 (minor, PLAUSIBLE). A conflict re-send of an `#[InternalHandler]` goes to its `inputChannelName`; if that
  channel is shared with another handler the other handler would receive the re-send too. The re-send also drops
  the time to live. Reproduce; re-send to the collected handler's own execution channel for every handler kind and
  keep the remaining time to live. Add conflict re-send tests for EventHandler and InternalHandler.
- C9 (minor). Missing small tests: release by hand twice; discard twice; discard racing an arriving message; the
  next message after a discard opens a new batch; a timer arriving after retention cleanup; the maximum-delay
  refusal worded for Collected; a collected handler next to an ordinary handler on one event; a closure `groupBy`
  that throws or returns null; a string expression reading `headers`; console release and discard of a batch.

## Guidelines
- C10 (should fix, public-api 6). The unknown-size marker is a bare `-1`, compared as `< 0`, `<= 0` and `> 0` in
  different places, and passed raw to callbacks typed `?int`. One named constant on the store interface, one
  predicate, null passed to callbacks.
- C11 (should fix, public-api 6a). `size` means the recorded count on `CollectedMessages` and the configured
  maximum in batch listings. Batch listing rows get their own shape with no gathering vocabulary: `groupId`,
  `endpointId`, `groupKey`, `state`, `maxSize` (null when none), `recordedMessages` (count), `openedAt` (time of
  the first message), `deadline` (null when no maxWait), and on closed rows `releaseReason`. No
  `gatherChannelName`, `accountedPartNumbers`, `missingPartNumbers`, `latePartCount`. Array-shape docblock.
  Console column "Maximum size"; console prints "no maximum size" / "no maximum wait" instead of blanks, as Pulse
  does; console and Pulse show opened-at and, for closed batches, the release reason.
- C12 (should fix, public-api 6a). The endpoint id is called `handler` in the new columns, store methods and
  in-memory keys. Use `endpoint_id` / `$endpointId` throughout.
- C13 (should fix, error-handling 1). Every refusal and runtime error of collecting names the handler the same way:
  `Class::method (endpointId 'x')`. Not the routing key, not the event class, not the bare method.
- C14 (should fix, error-handling 1). The missing-endpointId refusal a collected handler gets is the generic
  asynchronous one. For a collected handler the collecting refusal wins: it says open batches are stored under the
  endpointId so it must stay stable across deploys, and shows what to write. Still one message per mistake.
- C15 (should fix, error-handling 1). Subscription refusals show the exact attribute to write, for each handler
  kind, and say why (the first parameter is the batch, so the subscription cannot be inferred).
- C16 (should fix, error-handling 1). The durable-storage refusal, when an application has both a gathering
  splitter and a collected handler, names both and does not offer "remove gatherChannelName" as the only way out;
  the collecting variant keeps the per-integration hint the gathering one has.
- C17 (should fix, error-handling 1, PLAUSIBLE). A failing `groupBy` expression or closure surfaces with the handler
  (as in C13), the expression or the closure's class and method (not the word 'closure'), and the way out; tests
  assert the whole message.
- C18 (should fix, error-handling 1d, PLAUSIBLE). A `#[Collected]` method carrying two handler attributes is
  refused at bootstrap with the way out (one collected handler per method), tested.
- C19 (HELD, see H2). Enterprise wiring for collecting inside the Apache-2.0 `MessageProcessorActivatorBuilder`.
- C20 (nit). Named arguments for calls with three or more arguments in the new code; rename
  `$hasGatheringSplitters` now that it is also true for collected handlers; run php-cs-fixer over every changed
  file including tests and apps/Pulse (inline fully qualified names, unordered imports, repeated blank lines and
  blank lines before closing braces were found).
- C21 (nit). `CollectingPayloadType` re-implements the "first parameter or #[Payload]" rule the expression compiler
  owns. Reuse the compiler's own resolution if it exposes it; otherwise keep it and make sure both parameter forms
  are covered by one test that would fail if the two drift.

## Breaking changes
- C22 (should fix). The upgrade entry sits after the upgrade checklist; move it next to the gathering entry as a
  subsection. Add the reserved names `ecotone:collecting:*` and `ecotone.collecting.*` (adjusted to H1's outcome),
  say that batches are released, discarded and cleaned with the shared commands, and say that an application
  already running PR #765's prerelease tables gets a "column does not exist" error from the gathering listing
  until the tables are migrated. Give the reason for "upgrade every consumer before adding the marker".
- C23 (minor, PLAUSIBLE). Service ids `ecotone.collecting.handler.<endpointId>[.core|.enterprise]` collide for
  endpoints `a` and `a.core`. Use ids that cannot nest, as was done for splitters (M17 of the gathering review).

## Developer experience
- C24 (should fix). The guide has no test. Add a "Test it" section: `EcotoneLite::bootstrapFlowTesting` with the
  licence key, that a release happens only when the channel is consumed (`run()`), and maxWait with the pinned
  clock (`advanceTimeBy`, then `run()` again). Say where the licence key goes in an application.
- C25 (should fix). Closure `groupBy`: state that closures in attributes need PHP 8.5, what PHP itself reports on
  8.2 to 8.4 ("Constant expression contains invalid operations"), the string form to use there, and the
  payload-first rule in plain words.
- C26 (should fix). Pulse: a service with collected handlers always shows the collected-batches section (with an
  empty-state text of its own), and the split-parts introduction and "No gathering groups reported" are shown only
  when the service gathers.
- C27 (minor). Guide wording: say the in-memory difference in plain words; replace internals ("deferred timeout
  publication", "versioned group row and conflict re-send") with what the developer observes; lead the hot-key
  note with "several consumers adding to one key race, the loser is re-queued; for a hot key run one consumer";
  move the endpointId-rename warning next to "set an explicit endpointId"; show how to obtain the gateway and list
  the keys of a listing row.
- C28 (minor). Release reason cases match the attribute parameters: `MAX_SIZE_REACHED`, `MAX_WAIT_REACHED`,
  `MANUAL_RELEASE`.

## Held for the maintainer
- H1. Console commands: listing is `ecotone:collecting:list` while release, discard and cleanup exist only as
  `ecotone:gathering:*`. One family or two.
- H2 (C19). Where the Enterprise wiring of collecting (and of gathering in PR #765) lives.
- H3. Which headers the batch message given to the handler carries (design decision 14 says framework headers
  only; tenant and tracing headers are then missing).

## Maintainer decisions (2026-11-14)
- H1: one console family. Listing becomes `ecotone:gathering:list-batches`; `ecotone:collecting:list` is removed.
- H2 / C19: the gathered and the collected handler construction both move out of the Apache-2.0 builder into one
  `licence Enterprise` collaborator, in this pull request.
- C29: `CollectedMessages` exposes `messages`, a list in recording order of per-message objects with `payload` and
  `headers`; no parallel `payloads` / `headers` properties.
- C30 (H3): the batch message carries the headers of the batch's first message, minus the headers that must not
  cross the boundary (routing slip, reply channel, delivery delay, time to live, priority, framework gathering
  headers, message id and timestamp). The timer uses the same exclusions.

- C6: open-slot guard approved after reproduction on PostgreSQL, MySQL and MariaDB. Nullable open_slot is 1
  while open and NULL when closed, unique with endpoint_id and group_key_hash. Release/discard clear it atomically
  in existing state/version writes; ordinary gathered rows keep NULL. Generation and its index are removed when
  they add no independent guarantee. Retention continues deleting every expired closed batch. Prove cleanup and
  discard/arrival races and list the schema additions in the upgrade note.
