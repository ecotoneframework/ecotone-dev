# Claude Code Instructions for Ecotone

For comprehensive guidelines, see [AGENTS.md](./AGENTS.md).

## Quick Reference

- Bootstrap a fresh checkout: `bin/setup` (idempotent, no manual `.env` or flags needed)
- Run a package's tests: `docker compose exec -u root app bash -lc "cd packages/<PackageName> && composer install && composer tests:ci"`
- Ecotone is the enterprise architecture layer for Laravel and Symfony — CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging via PHP attributes
- Use PHP 8.1+ attributes: `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`, `#[Asynchronous]`, `#[Aggregate]`, `#[Saga]`, `#[EventSourcingAggregate]`, `#[Projection]`
- Test with `EcotoneLite::bootstrapFlowTesting()`
- No comments in code - use descriptive method names instead
- Documentation: https://docs.ecotone.tech

