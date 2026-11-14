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

- C31 (maintainer, 2026-11-14): lost optimistic writes in gathering and collecting throw
  `ConcurrencyException`. Asynchronous endpoints under a database transaction use their default instant retries
  (three attempts, each in a new transaction). Remove conflict re-sending and the C1 replacement identity header;
  C8 is obsolete. Exhaustion follows the normal error channel and dead letter, with replay supported. Use one
  retry mechanism for every optimistic lock: replacement messages required separate identity handling and caused
  the C1 duplicate-collect bug. Prove the real-database races, automatic retry, exhaustion and replay; rewrite and
  report the existing gathering tests that asserted re-sending.

- C31 clarification: deterministic one-conflict interleavings prove default automatic retries. Free-running
  contention asserts invariants after replay through one consumer, without asserting a probabilistic failure
  count. A controlled exhaustion test asserts the exception in the dead letter and replay completion.
- C32: add the framework retry rule beside optimistic locking, with evidence and an eval, in a separate
  docs(skills) commit. No user-facing application skills change.


## C31 verification and superseded assertions

- C1: retained original-redelivery-after-commit coverage uses the original message id. Removed only the AMQP
  non-transactional replacement-message variant, since replacement messages no longer exist.
- C8: obsolete under C31; there is no conflict re-send channel or replacement lifetime. Independent operations
  coverage still proves that a manual trigger reaches only its collected handler on a shared InternalHandler
  input or EventHandler subscription.
- Rewritten gathering tests (not removed):
  `concurrent_part_reports_conflict_and_are_retried_without_reaching_the_dead_letter` becomes
  `concurrent_part_reports_complete_once_after_replaying_any_exhausted_conflicts_in_a_real_database_race`;
  `releasing_transaction_sees_a_part_committed_after_its_snapshot_was_established` now asserts instant retry of
  the same message; `conflicting_group_operation_is_redelivered_before_observing_a_fresh_snapshot` becomes
  `conflicting_group_operation_retries_the_original_message_in_a_fresh_transaction`, retaining manual, timeout,
  report, discard/part and discard/release interleavings.
- Added deterministic exhaustion/replay tests for gathered reports, collected appends and manual batch releases.
  Free-running contention replays failures through one consumer and asserts exact-once membership and maximum size.
- C32: `check-agent-skills` passes. After clarifying that standard application retry configuration is allowed,
  `bin/test-agent-skills framework-storage-and-transactions` passes all three cases at 1.00, including the new
  `handle-version-conflict` case. The first run's below-threshold scores remain in its local report; no CI run was
  retried. No syntax-only re-send check is added because it cannot distinguish forwarding and timers from retry
  across message builders and collaborators; the rule's reference records that boundary.


## Resolution index after rebase onto main

C8's earlier proposed mechanism is superseded by C31. H1, H2 and H3 are resolved by the recorded maintainer
choices; no held item remains. Test names below omit their `test_` prefix.

| Finding | Resolution commit | Verification |
|---|---|---|
| C1 | `5ef988066` | exhausted_conflicts_reach_the_dead_letter_and_replay_completes_the_batch_in_a_real_database_race (forced conflict, the original id replayed and collected once); same_message_delivered_twice_at_once_while_its_batch_closes_is_a_lost_write_and_collected_once_after_replay; every_original_message_redelivered_after_a_real_database_race_is_not_collected_again (no forced conflict); own message id retained, replacement-only variant removed |
| C2 | `210298f52` | max_wait_timer_keeps_the_first_messages_tenant_for_its_database_connection |
| C3 | `a249aa57a`, `5ef988066` | max_size_plus_one_concurrent_messages_keep_the_next_partial_batch_in_a_real_database_race; max_wait_timer_racing_an_append_releases_each_message_once_in_a_real_database_race; manual_release_racing_an_append_releases_each_message_once_in_a_real_database_race |
| C4 | `dea15fc27` | failed_max_wait_or_manual_release_is_retried_with_the_same_batch; dead_lettered_release_keeps_collecting_past_the_deadline_until_replayed; dead_lettered_size_filling_message_joins_a_later_batch_when_replayed |
| C5 | `dea15fc27` | real_dbal_channel_restores_each_object_by_its_type_header_before_string_grouping; real_dbal_channel_exposes_payload_without_a_type_header_as_it_arrived; real_dbal_channel_converts_each_payload_for_a_typed_grouping_closure |
| C6 | `af3cefbb2` | cleanup_racing_two_arriving_messages_keeps_one_open_batch_in_a_real_database_race; discard_racing_an_arriving_message_frees_the_slot_atomically_in_a_real_database_race |
| C7 | `cf0ce9a22` | empty_boolean_and_float_group_keys_are_refused; integer_and_string_keys_share_a_batch_while_case_and_long_keys_stay_distinct |
| C8 | `5ef988066` | Obsolete: no conflict re-send or replacement time to live; independent shared-input manual-trigger tests retained |
| C9 | `0675103a0`, `af3cefbb2`, `f825d9955` | manual_release_requested_twice_invokes_the_batch_handler_once; discard_requested_twice_is_idempotent_and_the_next_message_opens_a_new_batch; a_timer_arriving_after_retention_cleanup_has_no_effect; console_commands_release_and_discard_collected_batches; string_group_expression_can_read_each_messages_headers; collected_and_ordinary_handlers_of_one_event_keep_their_own_delivery_frequency; collected_max_wait_exceeding_the_channels_maximum_names_the_handler_and_ways_out; existing race/closure cases |
| C10 | `267e6fa66`, `398fae2a2` | max_wait_only_collects_a_batch_without_a_known_size; batch_console_listing_uses_endpoint_id_and_hides_an_unknown_size |
| C11 / H1 | `a2a653af0` | batch_listings_name_the_maximum_recorded_count_and_first_recording_time; batch_console_listing_uses_endpoint_id_and_hides_an_unknown_size; group_listings_remain_separate_from_collected_batch_listings |
| C12 | `af3cefbb2` | open_batches_are_listed_by_collected_endpoint_id; endpoint_id storage and endpointId API naming |
| C13 | `d1cef079a`, `f825d9955` | CollectingTest bootstrap refusals; failing_group_expression_names_the_handler_and_expression_without_opening_a_batch |
| C14 | `4c1938c48` | collected_internal_handler_requires_an_explicit_endpoint_id; collected_command_handler_requires_an_explicit_endpoint_id; collected_event_handler_requires_an_explicit_endpoint_id |
| C15 | `4b8e6e466` | collected_event_handler_without_explicit_subscription_is_refused_at_bootstrap; collected_command_handler_without_explicit_subscription_is_refused_at_bootstrap; collected_internal_handler_without_explicit_subscription_is_refused_at_bootstrap |
| C16 | `f783e369a` | mixed_gathering_and_collecting_storage_refusal_names_both_flows; collected_handler_requires_durable_storage_outside_flow_testing |
| C17 | `f825d9955` | failing_group_expression_names_the_handler_and_expression_without_opening_a_batch; failing_or_null_group_closure_names_its_owner_and_the_way_out |
| C18 | `8ee649d4b` | collected_method_with_two_handler_attributes_is_refused_at_bootstrap |
| C19 / H2 | `3a527ee98` | Enterprise GatheringHandlerCompiler; collecting licence/bootstrap and gathering regression tests |
| C20 | `8c35ee273`, `815b4fc07`, `398fae2a2` | Named-argument/spacing sweep; DbalGatheringModule flag renamed usesGatheringStorage; php-cs-fixer run over every changed file in packages and apps/Pulse; existing suites unchanged |
| C21 | `f825d9955` | group_key_can_be_a_closure_with_payload_first_or_explicit_after_a_header; compiler exposes no reusable payload-type query |
| C22 | `192269028`, `5ef988066` | Upgrade entry alongside gathering: reserved services/commands and consumer rollout. The prerelease-table parts (migrating or recreating gathering tables created before this change, the "column does not exist" note) are withdrawn by maintainer decision C34 |
| C23 | `3d296a813` | endpoint_ids_with_core_suffixes_compile_independent_collected_handlers |
| C24 | `192269028` | Guide Test it example executed with licence, pinned time and run() |
| C25 | `192269028` | Guide specifies PHP 8.5 closure syntax, older-version error and string alternative; closure tests use RequiresPhp |
| C26 | `a2a653af0` | collected_batches_section_has_its_own_empty_state_without_split_parts_copy; closed_collected_batch_displays_its_release_reason |
| C27 | `192269028`, `5ef988066` | Guide explains memory rollback limit, hot-key retries, endpoint identity and gateway listing fields |
| C28 | `7ae125e39` | release_reason_names_match_the_configured_size_and_wait_limits |
| C29 | `e212f0804` | batch_contains_ordered_message_objects_with_their_own_payload_and_headers |
| C30 / H3 | `f9ce84450` | batch_inherits_the_first_messages_user_headers_and_retains_each_messages_own_headers; first_messages_delivery_headers_do_not_cross_the_batch_or_output_boundary |
| C31 | `5ef988066` | Rewritten gathering conflict tests listed above; deterministic append/release/report exhaustion and replay; three-engine concurrency invariants |
| C32 | `b6b1c1bdf` | check-agent-skills; all three storage eval cases score 1.00 after the rubric clarification |

The base moved from the gathering development history at `3ab80144b` to main at `09d1879ec`: gathering is now
squash-merged as `ca6b0ed86` (#765), and #767 converts expression results to parameter types. The rebase had no
conflicts. Collecting uses the existing attribute-expression executor directly for the key; closure parameter
converters share the upstream conversion behavior. String/closure grouping and both closure payload forms remain
covered after the rebase. The pull request now targets main.


## Review of the changes made after the first review, PR #768 head 183019490 (2026-11-14)

One read-only review. Core conflict handling confirmed correct for endpoints running in a database transaction
(every lost write throws before writing anything else; each retry attempt starts a new transaction and snapshot;
a retried, dead-lettered and replayed message is collected once). No BLOCKING finding.

- R1 (should fix, high). Collecting on an endpoint WITHOUT a database transaction (`#[WithoutDatabaseTransaction]`,
  `withTransactionOnAsynchronousEndpoints(false)`) loses batches and nothing says so: the release commits on its
  own, a failing batch handler is not re-run by a retry or replay (message already recorded, batch already
  closed); a crash between the group update and the part insert counts a message with no part; the in-memory
  store behaves differently (it restores on handler failure). Follow the gathering precedent on main
  (docs/message-gathering.md, the "you lose these guarantees" section and its tests
  GatheringConformanceTest around lines 1806 and 1858): document the lost guarantees for collecting in the same
  form and add the matching tests. Also: the ConcurrencyException text must not suggest raising instant retries
  as a way out for an endpoint without a transaction.
- R2 (should fix). Gathering without a transaction changed silently: a conflict used to be re-sent on any endpoint;
  now nothing retries by default there and it goes to the error channel. Upgrade note and guide say so; the
  exception names the way out for that case (turn the endpoint transaction on, or configure delayed retries);
  one test of a gathering conflict without a transaction.
- R3 (minor, PLAUSIBLE). Same message id delivered twice at once while the first delivery's batch closes: the
  second opens its own batch and fails the part insert with a raw unique-violation exception. Reproduce; treat as
  a duplicate (return) so it never depends on the retry configuration.
- R4 (minor). A lock wait timeout is reported as "another consumer won the optimistic write". Translate only
  genuine conflicts (unique violation on the store's own indexes, serialization failure, deadlock) and let a lock
  timeout surface as itself; check the previous-exception chain.
- R5 (minor, PLAUSIBLE). The shared header exclusion (GatheringMessageContext) is narrower than the framework's own
  propagation list: aggregate id headers of the first message reach the batch message and its output, and
  `ecotone_retry_number` / dead-letter replay markers cross into the maxWait timer. Reuse the framework's list;
  extend the boundary test with these headers.
- R6 (minor). `CollectedMessage->headers` differs by store: in memory it includes acknowledgement, polling and
  reply-channel headers that DBAL strips. Same content from both; the conformance test asserts the full key set.
- R7 (minor). Service ids `ecotone.gathering.handler.<endpointId>[.core|.enterprise]` still clash for endpoints `a`
  and `a.core`; make them non-nesting like the collecting ids.
- R8 (minor). `test_original_message_redelivery_after_a_real_database_conflict_is_not_collected_twice` forces no
  conflict; rename it to what it proves, or use the forced interleaving, and correct the C1 index row.
- R9 (minor). Rule 8a's title is broader than its body; title it for a lost optimistic write. Wrap lines over 120
  characters in the skill and its grader.
- Also (coordinator): the collecting licence refusal uses shorter wording than the gathering one; use the same
  full wording (Symfony, Laravel or Tempest configuration, the `licenceKey:` argument of EcotoneLite, the trial).

### Resolution of R1-R9

Test names omit their `test_` prefix.

| Finding | Resolution commit | Verification |
|---|---|---|
| R1 | `35d1dbbd9` | collected_handler_runs_without_a_transaction_when_asynchronous_endpoints_run_without_one; failing_collected_handler_without_a_transaction_leaves_its_batch_released_without_running_it_again; guide lists the lost guarantees and the in-memory difference; ConcurrencyException no longer offers instant retries to an endpoint without a transaction |
| R2 | `35d1dbbd9` | gathering_conflict_on_an_endpoint_without_the_framework_transaction_reaches_the_error_channel_without_a_retry (PostgreSQL, MySQL, MariaDB); upgrade note and gathering guide; exception names turning the transaction on or ErrorHandlerConfiguration delayed retries |
| R3 | `cf9296de9` | same_message_delivered_twice_at_once_while_its_batch_closes_is_a_lost_write_and_collected_once_after_replay (reproduced: raw UniqueConstraintViolationException in the dead letter). Resolved as a lost write, not by returning: PostgreSQL aborts the transaction at the failed insert after the batch row was written |
| R4 | `cf9296de9` | lock_wait_timeout_surfaces_as_itself_rather_than_as_a_lost_optimistic_write (red on MySQL before the fix); only deadlock/serialization failures and unique violations in the previous-exception chain become ConcurrencyException |
| R5 | `4d1b66811` | first_messages_delivery_headers_do_not_cross_the_batch_or_output_boundary extended with aggregate id, retry number and replay marker; max_wait_timer_does_not_carry_the_first_messages_retry_or_replay_markers; one FrameworkHeaders exclusion shared with the header propagator |
| R6 | `347bce9f2` | each_collected_message_carries_the_same_header_names_from_every_store (full key set, both stores) |
| R7 | `882d54e7e` | endpoint_ids_with_core_suffixes_compile_independent_gathered_handlers |
| R8 | `0ef6713f7` | renamed to every_original_message_redelivered_after_a_real_database_race_is_not_collected_again; C1 row corrected |
| R9 | `ccb3bfa45` | check-agent-skills; rule 8a retitled, skill and grader lines within 120 characters |
| Licence wording | `0ef51fc36` | collected_handler_requires_an_enterprise_licence asserts the full gathering-style wording |
| C33 (maintainer) | `39f82b12e` | gathered_group_listing_rows_use_the_shared_group_shape; batch_listing_rows_use_the_shared_group_shape; batches_are_listed_by_endpoint_id_and_not_by_channel_name; console_lists_a_batch_by_endpoint_id_in_the_shared_group_columns; console_lists_open_group_progress; gathered_handler_without_an_explicit_endpoint_id_is_refused_at_bootstrap; Pulse page_lists_gathered_groups_and_collected_batches_in_one_table and service_with_only_collected_handlers_shows_the_shared_empty_state; every gathering listing, console and Pulse test rewritten to the endpointId selector |


## Review of the listing unification, PR #768 range 7f2a82549..f9aa4f981 (2026-11-14)

No BLOCKING finding. Confirmed: gathering paths unchanged in the unified arrival step; the two kinds cannot cross
(batch lookup needs a key hash and an open slot, both NULL on gathered rows); no index can reject or merge gathered
rows now that they carry an endpoint id; one meaning and one time unit per row field.

- S1 (should fix). `ecotone:gathering:list <old gather channel name>` and `listOpenGroups('<channel>')` return an
  empty result silently, so a stuck group looks like "no groups". An id that is neither a gathered nor a collected
  endpointId AND has no stored groups is refused: if it is a gather channel name, the message names that channel's
  gathered handler endpointId; otherwise it lists the known endpoint ids. An id with stored groups but no handler
  any more (renamed or removed endpoint) is still listed, so its groups can be released or discarded.
- S2 (minor). Upgrade note: "a gathered handler could leave its endpointId to be generated" was never true on main
  (the asynchronous module already refused it). Drop that sentence and its adaptation step; the new message only
  replaces a generic refusal.
- S3 (minor). Upgrade note and gathering guide: groups created before this change have no endpoint id, still
  complete, release, discard and clean up, but are not listed; a renamed endpointId leaves its open groups listed
  only under the old id (see S1). One line each.
- S4 (minor, PLAUSIBLE). Pulse reading a snapshot from a service built before this change shows empty columns and
  an opened-at of 1970. Guard missing fields in the template (show a dash), tested with an old-shape snapshot.
- S5 (nit). In docs/message-gathering.md give the example's gathered handler an endpointId different from its
  input channel, so the list example shows that the argument is the endpointId.

### Resolution of S1-S5

| Finding | Resolution commit | Verification |
|---|---|---|
| S1 | `b4dc636c8` | listing_a_gather_channel_name_names_its_gathered_handler_endpoint_id and listing_an_unknown_endpoint_id_names_the_known_endpoint_ids (gateway open and closed listings and the console command, both stores); batches_stored_under_an_endpoint_id_without_a_current_handler_are_still_listed (DBAL, discard works under the old id) |
| S2 | `39db8f654` | Upgrade note no longer claims a generated gathered endpoint id was accepted; the adaptation step is removed |
| S3 | — | Withdrawn by maintainer decision C34; the note that a renamed endpoint's open groups stay listed under the old id is kept in the gathering guide |
| S4 | — | Withdrawn by maintainer decision C34: the dash guards and their test are removed |
| S5 | `39db8f654` | Guide example handler endpointId `import.summary.handler`, used by the list example |

### Maintainer decision C34 (2026-11-14)

Ecotone 2.0 is a beta and gathering has never been in a tagged release, so nothing in this pull request stays
compatible with the gathering merged to main on 2026-11-14 (#765). Removed: the Pulse dash guards for rows in the
pre-unification shape and their test; the upgrade entries comparing unreleased states ("Gathering conflicts use
the framework retry strategy", "Gathering groups are listed by the gathered handler's endpointId") and every
sentence about gathering tables or groups created before this change; the matching guide and spec sentences; the
nullable `endpoint_id` and `opened_at` group columns, which existed only for rows written without them, and the
in-memory `groupKey` fallbacks. The still-true facts (conflicts throw `ConcurrencyException` and use the default
retries; listings are selected by endpointId) are folded into the feature entries. S3, S4 and the prerelease-table
parts of C22 are withdrawn.
