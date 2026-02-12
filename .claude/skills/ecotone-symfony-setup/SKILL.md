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
    # Service name for distributed architecture
    serviceName: 'my_service'

    # Auto-load classes from src/ directory (default: true)
    loadSrcNamespaces: true

    # Additional namespaces to scan
    namespaces:
        - 'App\CustomNamespace'

    # Fail fast in dev (validates configuration on boot)
    failFast: true

    # Default serialization format for async messages
    defaultSerializationMediaType: 'application/json'

    # Default error channel for async consumers
    defaultErrorChannel: 'errorChannel'

    # Memory limit for consumers (MB)
    defaultMemoryLimit: 256

    # Connection retry on failure
    defaultConnectionExceptionRetry:
        initialDelay: 100
        maxAttempts: 3
        multiplier: 2

    # Skip specific module packages
    skippedModulePackageNames: []

    # Enterprise licence key
    licenceKey: '%env(ECOTONE_LICENCE_KEY)%'
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
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): SymfonyConnectionReference
    {
        return SymfonyConnectionReference::defaultManagerRegistry('default');
    }
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
use Ecotone\Dbal\Configuration\DbalConfiguration;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbalConfig(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            ->withDoctrineORMRepositories(true);
    }
}
```

Configure entity mappings in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_DSN)%'
                charset: UTF8
    orm:
        auto_generate_proxy_classes: '%kernel.debug%'
        entity_managers:
            default:
                connection: default
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src'
                        prefix: 'App'
                        alias: App
```

Aggregates become Doctrine entities:

```php
use Doctrine\ORM\Mapping as ORM;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[Aggregate]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    #[Identifier]
    private string $orderId;

    #[ORM\Column(type: 'boolean')]
    private bool $cancelled = false;

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = new self();
        $order->orderId = $command->orderId;
        return $order;
    }

    #[CommandHandler]
    public function cancel(CancelOrder $command): void
    {
        $this->cancelled = true;
    }
}
```

## 6. Async Messaging with Symfony Messenger

Use Symfony Messenger transports as Ecotone message channels:

Configure transports in `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: 'doctrine://default?queue_name=async'
                options:
                    use_notify: false
            amqp_async:
                dsn: '%env(RABBITMQ_DSN)%'
```

Register as Ecotone channels via `#[ServiceContext]`:

```php
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;

class ChannelConfiguration
{
    #[ServiceContext]
    public function asyncChannel(): SymfonyMessengerMessageChannelBuilder
    {
        return SymfonyMessengerMessageChannelBuilder::create('async');
    }

    #[ServiceContext]
    public function amqpChannel(): SymfonyMessengerMessageChannelBuilder
    {
        return SymfonyMessengerMessageChannelBuilder::create('amqp_async');
    }
}
```

### Using DBAL Channels Directly

```php
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;

class ChannelConfiguration
{
    #[ServiceContext]
    public function ordersChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }
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
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;

class EcotoneConfiguration
{
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
}
```

With Doctrine ORM multi-tenant setup in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        default_connection: tenant_a_connection
        connections:
            tenant_a_connection:
                url: '%env(resolve:DATABASE_DSN)%'
                charset: UTF8
            tenant_b_connection:
                url: '%env(resolve:SECONDARY_DATABASE_DSN)%'
                charset: UTF8
    orm:
        entity_managers:
            tenant_a_connection:
                connection: tenant_a_connection
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src'
                        prefix: 'App'
            tenant_b_connection:
                connection: tenant_b_connection
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src'
                        prefix: 'App'
```