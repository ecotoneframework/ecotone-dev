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

**Ecotone is the enterprise architecture layer for Laravel and Symfony.**

One Composer package adds CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging to your existing application — all via declarative PHP 8 attributes on the classes you already have.

## OpenTelemetry integration

Distributed tracing for every message in your Ecotone application. Commands, events, queries, async handlers, saga steps, and projections are traced automatically — so the full causal chain across sync and async flows is visible in any OTLP-compatible backend (Jaeger, Tempo, Grafana, Honeycomb, Datadog, New Relic, and others).

- **Automatic span creation** per handler invocation
- **Trace context propagation** across async channels — traces don't break when a message crosses a queue
- **W3C Trace Context and B3** headers supported
- **Metrics and logs** can be attached alongside traces via the OpenTelemetry SDK

No manual instrumentation required — enabling the module traces every handler Ecotone already knows about.

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

PHP, OpenTelemetry, OTEL, Distributed Tracing, Observability, Ecotone, Message Driven Architecture
