# Tagged projection self-review

Reviewed ac7f8801d0d2c9c4fc0a8e2916380f92e72a6d26 against origin/main.
Focus: tag sequence checkpoints, paging, read model updates, and replay.

1. **Should-fix — custom event-store selection is silently ignored.**
   `packages/Ecotone/src/Projecting/Config/StreamFilterRegistryModule.php:234`
   wires every TagStreamSource to EventStore::class although FromTaggedStream
   accepts eventStoreReferenceName. TagStreamSource::load uses that injected
   default, and the partition providers also use the default store/connection.
   A projection configured for a separate store therefore reads default-store
   events or stays empty rather than projecting its configured source.

   Proposed test (unexecuted prediction):
   `test_a_tagged_projection_reads_its_configured_event_store` boots with
   EcotoneLite::bootstrapFlowTesting(), an application-owned EventStore service
   named concert_store, and a projection using
   FromTaggedStream('concert', eventStoreReferenceName: 'concert_store').
   Seed that service with concert-1/A1 and leave the default store empty.
   Trigger the projection through FlowTestSupport and assert through its query
   handler that concert-1 contains A1. Predicted failure: the read model is empty.
   Cover live wake-up and console backfill/rebuild as separate paths.

   Hypothesis: either resolve readers and partition enumeration against the
   configured store consistently, or refuse non-default references at bootstrap
   with an actionable exception and a message test.

The existing shared tests cover in-memory and DBAL partition independence,
multiple tag values, replay, asynchronous serialization, and paging. The
conformance suite includes a page boundary within one append: events sharing
one tag sequence stay together even when they exceed the requested limit.
No tests were executed during this review; docker compose ps reported no running
services. Full CI, database dialect checks and lint remain required.

Existing infrastructure context, not introduced by this diff: DBAL projection
state loading uses SELECT FOR UPDATE, and saving uses an unconditional upsert.
It does not implement the repository's required optimistic version check.
