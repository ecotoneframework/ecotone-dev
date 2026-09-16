# Claude Code Instructions for Ecotone

For comprehensive guidelines, see [AGENTS.md](./AGENTS.md).

## Quick Reference

- Bootstrap a fresh checkout: `docker compose up -d` then `docker compose exec app composer install` (no manual `.env` or flags needed)
- Run a package's tests: `docker compose exec app bash -lc "cd packages/<PackageName> && composer install && composer tests:ci"`
- Don't use `-u root` for `composer`/tests — it leaves `vendor/` root-owned on the host
- Ecotone is the enterprise architecture layer for Laravel and Symfony — CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging via PHP attributes
- Use PHP 8.1+ attributes: `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`, `#[Asynchronous]`, `#[Aggregate]`, `#[Saga]`, `#[EventSourcingAggregate]`, `#[Projection]`
- Test with `EcotoneLite::bootstrapFlowTesting()`
- No comments in code - use descriptive method names instead
- Documentation: https://docs.ecotone.tech

