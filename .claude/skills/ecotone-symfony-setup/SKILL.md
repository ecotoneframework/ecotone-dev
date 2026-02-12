---
name: ecotone-symfony-setup
description: >-
  Sets up Ecotone in a Symfony project: composer installation, bundle
  registration, YAML configuration, Doctrine ORM integration,
  SymfonyConnectionReference for DBAL, Symfony Messenger channels, async
  consumer commands, and ServiceContext. Use when installing Ecotone in
  Symfony, configuring Symfony-specific connections, or setting up
  Symfony async consumers.
---

# Ecotone Symfony Setup

## Overview

This skill covers setting up and configuring Ecotone within a Symfony application. Use it when installing Ecotone, registering the bundle, configuring database connections via Doctrine, setting up async messaging with Symfony Messenger, integrating Doctrine ORM aggregates, or configuring multi-tenancy.

## 1. Installation

```bash
composer require ecotone/symfony-bundle
```

Optional packages:

```bash
composer require ecotone/dbal   # Database support (DBAL, outbox, dead letter, event sourcing)
composer require ecotone/amqp   # RabbitMQ support
composer require ecotone/redis  # Redis support
composer require ecotone/sqs    # SQS support
composer require ecotone/kafka  # Kafka support
```

## 2. Bundle Registration

In `config/bundles.php`:

```php
<?php

use Ecotone\SymfonyBundle\EcotoneSymfonyBundle;

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    EcotoneSymfonyBundle::class => ['all' => true],
];
```

## 3. Configuration

In `config/packages/ecotone.yaml`:

```yaml
ecotone:
    serviceName: 'my_service'
    loadSrcNamespaces: true
    failFast: true
    defaultSerializationMediaType: 'application/json'
    defaultErrorChannel: 'errorChannel'
```

## 4. Database Connection (DBAL)

```php
#[ServiceContext]
public function databaseConnection(): SymfonyConnectionReference
{
    return SymfonyConnectionReference::defaultManagerRegistry('default');
}
```

## 5. Doctrine ORM Integration

Enable Doctrine ORM repositories:

```php
#[ServiceContext]
public function dbalConfig(): DbalConfiguration
{
    return DbalConfiguration::createWithDefaults()
        ->withDoctrineORMRepositories(true);
}
```

Annotate aggregates with both `#[ORM\Entity]` and `#[Aggregate]`:

```php
#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[Aggregate]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    #[Identifier]
    private string $orderId;
}
```

## 6. Async Messaging with Symfony Messenger

```php
#[ServiceContext]
public function asyncChannel(): SymfonyMessengerMessageChannelBuilder
{
    return SymfonyMessengerMessageChannelBuilder::create('async');
}
```

## 7. Running Async Consumers

```bash
bin/console ecotone:run <channel_name>
bin/console ecotone:run orders --handledMessageLimit=100
bin/console ecotone:run orders --memoryLimit=256
bin/console ecotone:run orders --executionTimeLimit=60000
bin/console ecotone:list
```

## 8. Multi-Tenant Configuration

```php
#[ServiceContext]
public function multiTenant(): MultiTenantConfiguration
{
    return MultiTenantConfiguration::create(
        tenantHeaderName: 'tenant',
        tenantToConnectionMapping: [
            'tenant_a' => SymfonyConnectionReference::createForManagerRegistry('tenant_a_connection'),
            'tenant_b' => SymfonyConnectionReference::createForManagerRegistry('tenant_b_connection'),
        ],
    );
}
```

## Key Rules

- `SymfonyConnectionReference::defaultManagerRegistry()` is the recommended approach (uses Doctrine ManagerRegistry)
- `SymfonyMessengerMessageChannelBuilder::create()` channel name must match a transport defined in `config/packages/messenger.yaml`
- Doctrine ORM aggregates need both `#[ORM\Entity]` and `#[Aggregate]` attributes
- Enable `DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(true)` for Doctrine entity persistence
- Always use `#[ServiceContext]` methods in a class registered as a service for configuration

## Additional resources

- [Configuration reference](references/configuration-reference.md) -- Full `ecotone.yaml` with all options and comments, all configuration option descriptions with defaults, `SymfonyConnectionReference` API table, and `doctrine.yaml` DBAL connection setup. Load when you need the complete YAML configuration or all available config options.
- [Integration patterns](references/integration-patterns.md) -- Complete class implementations for Symfony integration: full Doctrine entity aggregate with all imports, DBAL connection setup with multiple connections, Symfony Messenger channel configuration with `messenger.yaml`, DBAL-backed channels, multi-tenant setup with full `doctrine.yaml` for multiple entity managers, and Doctrine ORM entity mappings. Load when you need full working class files with imports and complete configuration examples.
