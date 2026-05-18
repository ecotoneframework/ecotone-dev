# Symfony Projection Quickstart

A **projection** in Ecotone is a read model built by replaying events from an event-sourced aggregate. Unlike the aggregate itself — which stores state as events — a projection maintains a denormalised, query-friendly table that can be wiped and rebuilt from the event stream at any time.

These two examples walk through the complete projection lifecycle using a `User` aggregate that emits `UserWasRegistered`, `UserNameWasChanged`, and `UserWasDeactivated` events.

## Pick your starting point

| Example | Pattern | When to use |
|---------|---------|-------------|
| [DatabaseReadModel](./DatabaseReadModel/) | Projection writes directly to the DB via Doctrine DBAL `Connection` | Simplest approach; straightforward SQL; no ORM overhead |
| [EntityReadModel](./EntityReadModel/) | Projection emits commands via `outputChannelName` to a stateful `#[Aggregate]` Doctrine entity | When you want the "auto-load + auto-save" sugar on a read model and Doctrine ORM's lifecycle callbacks |

**Start with DatabaseReadModel.** It gets the projection lifecycle working with minimal moving parts. Once you understand init → query → reset → delete, switch to EntityReadModel to see how a stateful Doctrine entity aggregate becomes the read model's persistence layer.

## What both examples share

- A `User` `#[EventSourcingAggregate]` with `RegisterUser`, `ChangeUserName`, and `DeactivateUser` commands
- `#[ProjectionV2]` + `#[FromAggregateStream(User::class)]` for automatic stream wiring
- `#[ProjectionInitialization]` and `#[ProjectionDelete]` lifecycle hooks
- `#[QueryHandler]` on the projection class for `user.listActive`
- A `run_example.php` script that walks the projection lifecycle and asserts on the read model state
