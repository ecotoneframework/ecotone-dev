# Claude Code Instructions for Ecotone

**[AGENTS.md](./AGENTS.md) is the guide** — project overview, monorepo layout, the dev loop, and a summary of the
coding conventions. **[docs/coding-conventions.md](./docs/coding-conventions.md) is the rules in full; read it
before writing code**, not after review.

## Quick Reference

- Bootstrap a fresh checkout: `docker compose up -d` then `docker compose exec app composer install` (no manual
  `.env` or flags needed)
- Run a package's tests: `docker compose exec app bash -lc "cd packages/<PackageName> && composer install && composer tests:ci"`
  — inside the container, one package at a time, never in parallel
- Run `vendor/bin/php-cs-fixer fix` on the **host**, never inside the container
- Don't use `-u root` for `composer`/tests — it leaves `vendor/` root-owned on the host
- Ecotone is the enterprise architecture layer for Laravel and Symfony — CQRS, Event Sourcing, Sagas, Projections,
  Workflows, and Outbox messaging via PHP attributes
- Every user-facing class is under `Ecotone\Api`: `Ecotone\Api\Attribute\CommandHandler`,
  `Ecotone\Api\Attribute\Aggregate`, `Ecotone\Api\Gateway\CommandBus`, `Ecotone\Api\Projecting\Projection`.
  Verify a name against the tree before writing it — the namespaces moved in 2.0
  (`upgrade/namespace-map-2.0.csv`)
- Test with `EcotoneLite::bootstrapFlowTesting()`, from the userland perspective only: no SQL on Ecotone's tables,
  no reflection, no internal service references
- Documentation: https://docs.ecotone.tech
