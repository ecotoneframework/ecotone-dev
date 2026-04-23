<p align="left"><a href="https://ecotone.tech" target="_blank">
    <img src="https://github.com/ecotoneframework/ecotone-dev/blob/main/ecotone_small.png?raw=true">
</a></p>

![Github Actions](https://github.com/ecotoneFramework/ecotone-dev/actions/workflows/split-testing.yml/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/ecotone/ecotone/v/stable)](https://packagist.org/packages/ecotone/ecotone)
[![License](https://poser.pugx.org/ecotone/ecotone/license)](https://packagist.org/packages/ecotone/ecotone)
[![Total Downloads](https://img.shields.io/packagist/dt/ecotone/ecotone)](https://packagist.org/packages/ecotone/ecotone)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/ecotone/ecotone/php.svg)](https://packagist.org/packages/ecotone/ecotone)

**Ecotone is the PHP architecture layer that grows with your system — without rewrites.**

From `#[CommandHandler]` on day one, to event sourcing, sagas, outbox, and distributed messaging at scale — one package, same codebase, no forced migrations between growth stages. Declarative PHP 8 attributes on the classes you already have. No base classes, no bus wiring, no retry config.

Built on Enterprise Integration Patterns — the same pattern language behind Spring Integration, Axon, NServiceBus, and Apache Camel — brought to PHP as attribute-driven code.

```php
class OrderService
{
    #[CommandHandler]
    public function placeOrder(PlaceOrder $command, EventBus $eventBus): void
    {
        $eventBus->publish(new OrderWasPlaced($command->orderId));
    }
}

class NotificationService
{
    #[Asynchronous('notifications')]
    #[EventHandler]
    public function whenOrderPlaced(OrderWasPlaced $event, NotificationSender $sender): void
    {
        $sender->sendOrderConfirmation($event->orderId);
    }
}
```

Every flow — sync, async, sagas, projections — runs through the same messaging pipeline in production and in tests. Swap the in-memory channel for RabbitMQ, Kafka, SQS, Redis, or DBAL in production; the test shape never changes.

Visit [ecotone.tech](https://ecotone.tech) to learn more.

> Works with [Symfony](https://docs.ecotone.tech/modules/symfony-ddd-cqrs-event-sourcing), [Laravel](https://docs.ecotone.tech/modules/laravel-ddd-cqrs-event-sourcing), or any PSR-11 framework via [Ecotone Lite](https://docs.ecotone.tech/install-php-service-bus#install-ecotone-lite-no-framework).

## Getting started

The [quickstart guide](https://docs.ecotone.tech/quick-start) in the [reference documentation](https://docs.ecotone.tech) is the fastest path to your first handler.
Read more on the [Ecotone Blog](https://blog.ecotone.tech).

## AI-Ready by design

Declarative attributes mean less infrastructure code for your coding agent to read and less boilerplate for it to generate — smaller context, faster iteration, more accurate results.

- **MCP Server**: `https://docs.ecotone.tech/~gitbook/mcp` — [Install in VSCode](vscode:mcp/install?%7B%22name%22%3A%22Ecotone%22%2C%22url%22%3A%22https%3A%2F%2Fdocs.ecotone.tech%2F~gitbook%2Fmcp%22%7D)
- **Agentic Skills**: Ready-to-use skills that teach any coding agent to correctly write handlers, aggregates, sagas, projections, and tests
- **LLMs.txt**: [ecotone.tech/llms.txt](https://ecotone.tech/llms.txt)
- **Context7**: Available via [@upstash/context7-mcp](https://github.com/upstash/context7)

Learn more: [AI Integration Guide](https://docs.ecotone.tech/other/ai-integration)

## Contribution

Read [Read me Development Context](./README-DEVELOPMENT-CONTEXT) for information about the Monorepo.
Visit [Ecotone's Documentation](https://docs.ecotone.tech/messaging/contributing-to-ecotone) for more information about contributing.

## Feature requests and issue reporting

Use [issue tracking system](https://github.com/ecotoneframework/ecotone/issues) for new feature request and bugs.
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

PHP, DDD, CQRS, Event Sourcing, Sagas, Projections, Workflows, Outbox, Symfony, Laravel, Service Bus, Event Driven Architecture
