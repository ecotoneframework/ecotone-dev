# Ecotone Quickstart Examples

Runnable examples covering the full Ecotone feature set — handlers, aggregates, sagas, event sourcing, projections, workflows, multi-tenancy, outbox, and more. Each example is self-contained and runs end-to-end in seconds.

```bash
docker compose up -d && docker exec -it ecotone-quickstart /bin/bash
```

Then pick any example directory and run it:

```bash
composer install
php run_example.php
```

## What's inside

- **Asynchronous** — `#[Asynchronous]` handlers with in-memory and DBAL channels
- **BuildingBlocks** — the core handler/aggregate/saga vocabulary in one place
- **BusinessInterface** — declarative DBAL queries via `#[DbalBusinessMethod]`
- **ErrorHandling** — retry strategies, error channels, and dead letter replay
- **EventProjecting / PartitionedProjection** — catch-up and partitioned projections on an event-sourced stream
- **EmittingEventsFromProjection** — projections that publish derived events
- **Microservices / MicroservicesAdvanced** — Distributed Bus with cross-service events and commands
- **MultiTenant** — per-tenant connections, event stores, and async channels (Laravel + Symfony variants)
- **OutboxPattern** — guaranteed message delivery via the outbox pattern
- **RefactorToReactiveSystem** — staged refactor from a procedural service to a message-driven one
- **StatefulProjection** — projections with internal state
- **Testing** — `EcotoneLite::bootstrapFlowTesting` patterns for sync and async flows
- **Workflows** — stateless workflows, saga-based workflows, and async stateless variants
- **CustomEventStoreProjecting** — custom event store implementations

---

<p align="left"><a href="https://ecotone.tech" target="_blank">
    <img src="https://github.com/ecotoneframework/ecotone-dev/blob/main/ecotone_small.png?raw=true">
</a></p>

![Github Actions](https://github.com/ecotoneFramework/ecotone-dev/actions/workflows/split-testing.yml/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/ecotone/ecotone/v/stable)](https://packagist.org/packages/ecotone/ecotone)
[![License](http://poser.pugx.org/ecotone/ecotone/license)](https://packagist.org/packages/ecotone/ecotone)
[![Total Downloads](http://poser.pugx.org/ecotone/ecotone/downloads)](https://packagist.org/packages/ecotone/ecotone)
[![PHP Version Require](http://poser.pugx.org/ecotone/ecotone/require/php)](https://packagist.org/packages/ecotone/ecotone)

**Ecotone is the enterprise architecture layer for Laravel and Symfony.**

One Composer package adds CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging to your existing application — all via declarative PHP 8 attributes on the classes you already have.

Visit [ecotone.tech](https://ecotone.tech) to learn more.

> Works with [Symfony](https://docs.ecotone.tech/modules/symfony-ddd-cqrs-event-sourcing), [Laravel](https://docs.ecotone.tech/modules/laravel-ddd-cqrs-event-sourcing), or any PSR-11 framework via [Ecotone Lite](https://docs.ecotone.tech/install-php-service-bus#install-ecotone-lite-no-framework).

## Getting started

See the [quickstart guide](https://docs.ecotone.tech/quick-start) and [reference documentation](https://docs.ecotone.tech). Read more on the [Ecotone Blog](https://blog.ecotone.tech).

## Feature requests and issue reporting

Use [issue tracking system](https://github.com/ecotoneframework/ecotone-dev/issues) for new feature request and bugs.
Please verify that it's not already reported by someone else.

## Contact

If you want to talk or ask questions about Ecotone

- [**Twitter**](https://twitter.com/EcotonePHP)
- **support@simplycodedsoftware.com**
- [**Community Channel**](https://discord.gg/GwM2BSuXeg)

## Support Ecotone

If you want to help building and improving Ecotone consider becoming a sponsor:

- [Sponsor Ecotone](https://github.com/sponsors/dgafka)
- [Contribute to Ecotone](https://github.com/ecotoneframework/ecotone-dev).

## Tags

PHP, DDD, CQRS, Event Sourcing, Sagas, Projections, Workflows, Outbox, Symfony, Laravel, Service Bus
