---
name: ecotone-symfony-setup
description: >-
  Sets up Ecotone in Symfony: composer installation, bundle registration,
  YAML configuration, Doctrine ORM integration, SymfonyConnectionReference
  for DBAL, Symfony Messenger channels, async consumer commands, and
  ServiceContext configuration. Use when installing, configuring, or
  integrating Ecotone with Symfony.
---

# Ecotone Symfony Setup

## 1. Installation

```bash
composer require ecotone/symfony-bundle
```

Optional packages:

```bash
# Database support (DBAL, outbox, dead letter, event sourcing)
composer require ecotone/dbal

# RabbitMQ support
composer require ecotone/amqp

# Redis support
composer require ecotone/redis

# SQS support
composer require ecotone/sqs

# Kafka support
composer require ecotone/kafka
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

### All Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `serviceName` | `null` | Service identifier for distributed messaging |
| `failFast` | `false` | Validates config at boot (auto-enabled in dev) |
| `loadSrcNamespaces` | `true` | Auto-scan `src/` for handlers |
| `namespaces` | `[]` | Additional namespaces to scan |
| `defaultSerializationMediaType` | `null` | Media type for async serialization |
| `defaultErrorChannel` | `null` | Error channel name |
| `defaultMemoryLimit` | `null` | Consumer memory limit (MB) |
| `defaultConnectionExceptionRetry` | `null` | Retry config for connection failures |
| `skippedModulePackageNames` | `[]` | Module packages to skip |
| `licenceKey` | `null` | Enterprise licence key |
| `test` | `false` | Enable test mode |

## 4. Database Connection (DBAL)

### Using Doctrine Manager Registry (Recommended)

Configure Doctrine DBAL in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_DSN)%'
                charset: UTF8
```

Register the connection for Ecotone via `#[ServiceContext]`:

```php
#[ServiceContext]
public function databaseConnection(): SymfonyConnectionReference
{
    return SymfonyConnectionReference::defaultManagerRegistry('default');
}
```

### SymfonyConnectionReference API

| Method | Description |
|--------|-------------|
| `defaultManagerRegistry(connectionName, managerRegistry)` | Default connection via Doctrine ManagerRegistry |
| `createForManagerRegistry(connectionName, managerRegistry, referenceName)` | Named connection via ManagerRegistry |
| `defaultConnection(connectionName)` | Default connection without ManagerRegistry |
| `createForConnection(connectionName, referenceName)` | Named connection without ManagerRegistry |

### Multiple Connections

```php
#[ServiceContext]
public function connections(): array
{
    return [
        SymfonyConnectionReference::defaultManagerRegistry('default'),
        SymfonyConnectionReference::createForManagerRegistry(
            'reporting',
            'doctrine',
            'reporting_connection'
        ),
    ];
}
```

## 5. Doctrine ORM Integration

Enable Doctrine ORM repositories so aggregates can be stored as Doctrine entities:

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

Use Symfony Messenger transports as Ecotone message channels. Configure transports in `config/packages/messenger.yaml`, then register as channels:

```php
#[ServiceContext]
public function asyncChannel(): SymfonyMessengerMessageChannelBuilder
{
    return SymfonyMessengerMessageChannelBuilder::create('async');
}
```

### Using DBAL Channels Directly

```php
#[ServiceContext]
public function ordersChannel(): DbalBackedMessageChannelBuilder
{
    return DbalBackedMessageChannelBuilder::create('orders');
}
```

## 7. Running Async Consumers

Ecotone auto-registers Symfony console commands:

```bash
# Run a consumer
bin/console ecotone:run <channel_name>

# With message limit
bin/console ecotone:run orders --handledMessageLimit=100

# With memory limit
bin/console ecotone:run orders --memoryLimit=256

# With time limit (milliseconds)
bin/console ecotone:run orders --executionTimeLimit=60000

# List available consumers
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

## Additional resources

- [Symfony integration patterns](references/symfony-patterns.md) — Complete configuration examples and full class definitions for Symfony integration. Load when you need: full `ecotone.yaml` with all options and comments, full `doctrine.yaml` with ORM entity manager mappings, complete Doctrine entity aggregate class, multiple DBAL connections setup, full Symfony Messenger YAML transport config with multiple channels, DBAL-backed message channel example, or multi-tenant `doctrine.yaml` with multiple entity managers.