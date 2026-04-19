# GitHub Copilot Instructions for Ecotone

For comprehensive guidelines, refer to [AGENTS.md](../AGENTS.md).

## Quick Reference

- Ecotone is the enterprise architecture layer for Laravel and Symfony — CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging via PHP attributes
- Use PHP 8.1+ attributes: `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`, `#[Asynchronous]`, `#[Aggregate]`, `#[Saga]`, `#[EventSourcingAggregate]`, `#[Projection]`
- Test with `EcotoneLite::bootstrapFlowTesting()`
- No comments in code - use descriptive method names instead
- Documentation: https://docs.ecotone.tech

