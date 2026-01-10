# Claude Code Instructions for Ecotone

For comprehensive guidelines, see [AGENTS.md](./AGENTS.md).

## Quick Reference

- Ecotone is a PHP framework for message-driven architecture (DDD, CQRS, Event Sourcing)
- Use PHP 8.1+ attributes: `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`
- Test with `EcotoneLite::bootstrapForTesting()`
- No comments in code - use descriptive method names instead
- Documentation: https://docs.ecotone.tech

