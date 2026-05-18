# Laravel Projection Quickstart

A **projection** in Ecotone is a read model built by replaying events from an event-sourced aggregate. Unlike the aggregate itself — which stores state as events — a projection maintains a denormalised, query-friendly table that can be wiped and rebuilt from the event stream at any time.

These two examples walk through the complete projection lifecycle using a `User` aggregate that emits `UserWasRegistered`, `UserNameWasChanged`, and `UserWasDeactivated` events.

## Pick your starting point

| Example | Pattern | When to use |
|---------|---------|-------------|
| [DatabaseReadModel](./DatabaseReadModel/) | Projection writes directly to the DB via `ConnectionInterface` | Simplest approach; straightforward SQL; no ORM overhead |
| [EloquentReadModel](./EloquentReadModel/) | Projection emits DTOs via `outputChannelName`; a writer service persists via Eloquent | When you need Eloquent lifecycle hooks, observers, mutators, or scopes on the read model |

**Start with DatabaseReadModel.** It gets the projection lifecycle working with minimal moving parts. Once you understand init → backfill → reset → delete, switch to EloquentReadModel to see how the `outputChannelName` pattern separates projection logic from persistence.

## What both examples share

- A `User` `#[EventSourcingAggregate]` with `RegisterUser`, `ChangeUserName`, and `DeactivateUser` commands
- `#[ProjectionV2]` + `#[FromAggregateStream(User::class)]` for automatic stream wiring
- `#[ProjectionInitialization]` and `#[ProjectionDelete]` lifecycle hooks
- `#[QueryHandler]` on the projection class for `user.listActive`
- A `run_example.php` script that exercises all seven lifecycle steps and asserts on the read model state
