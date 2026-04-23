# This is Read Only Repository
To contribute make use of [Ecotone-Dev repository](https://github.com/ecotoneframework/ecotone-dev).

<p align="left"><a href="https://ecotone.tech" target="_blank">
    <img src="https://github.com/ecotoneframework/ecotone-dev/blob/main/ecotone_small.png?raw=true">
</a></p>

![Github Actions](https://github.com/ecotoneFramework/ecotone-dev/actions/workflows/split-testing.yml/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/ecotone/ecotone/v/stable)](https://packagist.org/packages/ecotone/ecotone)
[![License](https://poser.pugx.org/ecotone/ecotone/license)](https://packagist.org/packages/ecotone/ecotone)
[![Total Downloads](https://img.shields.io/packagist/dt/ecotone/ecotone)](https://packagist.org/packages/ecotone/ecotone)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/ecotone/ecotone/php.svg)](https://packagist.org/packages/ecotone/ecotone)

**Ecotone is the PHP architecture layer that grows with your system — without rewrites.**

From `#[CommandHandler]` on day one, to event sourcing, sagas, outbox, and distributed messaging at scale — one package, same codebase, no forced migrations between growth stages. Declarative PHP 8 attributes on the classes you already have.

## Doctrine DBAL integration

Database-backed foundation for Ecotone, built on Doctrine DBAL. Use your existing relational database as a message transport, outbox, or dead letter store — no extra infrastructure required to get started.

- **DBAL message channel** — durable asynchronous channel backed by your database
- **Outbox pattern** — atomic commit of business state and outbound messages, so no event is lost on crash
- **Dead Letter Queue** — failed messages persisted to your database, inspectable and replayable
- **Document Store** — aggregates persisted as JSON documents, no ORM required
- **Business Interfaces** — declarative DBAL query methods via `#[DbalBusinessMethod]`
- **Transactional message handling** — each handler wrapped in a DB transaction automatically

Works with Doctrine ORM, Eloquent, and raw PDO — pick the ORM your framework already uses.

Visit [ecotone.tech](https://ecotone.tech) to learn more.

> Works with [Symfony](https://docs.ecotone.tech/modules/symfony-ddd-cqrs-event-sourcing), [Laravel](https://docs.ecotone.tech/modules/laravel-ddd-cqrs-event-sourcing), or any PSR-11 framework via [Ecotone Lite](https://docs.ecotone.tech/install-php-service-bus#install-ecotone-lite-no-framework).

## Getting started

See the [quickstart guide](https://docs.ecotone.tech/quick-start) and [reference documentation](https://docs.ecotone.tech). Read more on the [Ecotone Blog](https://blog.ecotone.tech).

## AI-Ready documentation

Ecotone ships with MCP server, Agentic Skills, and LLMs.txt for any coding agent. See the [AI Integration Guide](https://docs.ecotone.tech/other/ai-integration).

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

PHP, Doctrine DBAL, Ecotone, Outbox, Dead Letter Queue, Document Store, Message Channel, Transactional Messaging
