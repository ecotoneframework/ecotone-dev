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

## Applies when

- Installing Ecotone into a Symfony application and registering its bundle
- Writing `config/packages/ecotone.yaml` or setting the service name, error channel or licence key
- Pointing Ecotone's DBAL features (outbox, dead letter, event store, DBAL channels) at a Doctrine connection
- Running asynchronous handlers on a Symfony Messenger transport and starting the consumer
- Storing aggregates as Doctrine ORM entities

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
    loadSrcNamespaces: true
    namespaces: []
    licenceKey: '%env(ECOTONE_LICENCE_KEY)%'
```

Everything else is a `ServiceConfiguration` returned from a `#[ServiceContext]` method. `#[ServiceContext]` methods
live in any class under `src/` (or a listed namespace); Ecotone instantiates that class itself, so it takes no
constructor parameters and needs no entry in `services.yaml`:

```php
#[ServiceContext]
public function serviceConfiguration(): ServiceConfiguration
{
    return ServiceConfiguration::createWithDefaults()
        ->withServiceName('my_service')
        ->withDefaultSerializationMediaType('application/json')
        ->withDefaultErrorChannel('errorChannel');
}
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

Multi-tenancy is an Ecotone Enterprise feature: without `licenceKey` the application refuses to boot with a
`LicensingException`.

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

## Rules

### 1. Install `ecotone/symfony-bundle` and register `EcotoneSymfonyBundle`
The bundle class is `Ecotone\SymfonyBundle\EcotoneSymfonyBundle`, enabled for all environments in
`config/bundles.php`.
- **Expected:** setup is `composer require ecotone/symfony-bundle` (plus `ecotone/dbal` for database features) and
  `Ecotone\SymfonyBundle\EcotoneSymfonyBundle::class => ['all' => true]` in `config/bundles.php`.
- **Verified by:** `eval:symfony-orders-setup`

### 2. `ecotone.yaml` holds only the bundle's five keys
`config/packages/ecotone.yaml` reads `loadSrcNamespaces`, `namespaces`, `enabledModulePackageNames`, `licenceKey` and
`test`; any other key is refused at bootstrap. The service name, default error channel and serialization media type
are a `ServiceConfiguration` returned from one `#[ServiceContext]` method.
- **Expected:** `ecotone.yaml` contains no key outside those five; the service name and error channel are set with
  `ServiceConfiguration::createWithDefaults()->withServiceName(...)->withDefaultErrorChannel(...)` in a
  `#[ServiceContext]` method.
- **Verified by:** `eval:symfony-orders-setup`

### 3. A `#[ServiceContext]` class has no constructor dependencies
Ecotone instantiates the configuration class itself while it reads its configuration, so the class is never
fetched from the Symfony container, and a constructor with parameters is refused at bootstrap.
- **Expected:** the configuration class has no constructor parameters and is not wired as a Symfony service; its
  methods return extension objects (`ServiceConfiguration`, `SymfonyConnectionReference`, channel builders).
- **Verified by:** `eval:symfony-orders-setup`

### 4. Point DBAL at a Doctrine connection with `SymfonyConnectionReference`
`SymfonyConnectionReference::defaultManagerRegistry('default')` uses the `doctrine.dbal.connections` entry of that name
through Doctrine's ManagerRegistry, which Doctrine ORM repositories also use.
- **Expected:** the connection is configured as `SymfonyConnectionReference::defaultManagerRegistry('<doctrine
  connection name>')` returned from a `#[ServiceContext]` method, not as a DSN string or a hand-built DBAL connection.
- **Verified by:** `eval:symfony-orders-setup`

### 5. A Messenger channel is named after its transport and consumed with `ecotone:run`
`SymfonyMessengerMessageChannelBuilder::create('async')` uses the Messenger transport `async` from
`config/packages/messenger.yaml` as channel `async`, so the transport name is the `#[Asynchronous]` name. Ecotone
consumes it with `bin/console ecotone:run async`.
- **Expected:** a transport is declared in `messenger.yaml`, the channel builder and `#[Asynchronous('...')]` use that
  transport's name, and the consumer is started with `bin/console ecotone:run <name>` (not only with
  `messenger:consume`).
- **Verified by:** `eval:symfony-orders-setup`

### 6. Doctrine entities become aggregates once ORM repositories are enabled
An entity needs both `#[ORM\Entity]` and `#[Aggregate]`, an `#[Identifier]` on its id property, and
`DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(true)`, which is off by default.
- **Expected:** the entity carries `#[ORM\Entity]`, `#[Aggregate]` and `#[Identifier]` on the `#[ORM\Id]` property, and
  a `#[ServiceContext]` method returns `DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(true)`.
- **Verified by:** `eval:symfony-orders-setup`

## Additional resources

- [Configuration reference](references/configuration-reference.md) -- Full `ecotone.yaml` with all options and comments, all configuration option descriptions with defaults, `SymfonyConnectionReference` API table, and `doctrine.yaml` DBAL connection setup. Load when you need the complete YAML configuration or all available config options.
- [Integration patterns](references/integration-patterns.md) -- Complete class implementations for Symfony integration: full Doctrine entity aggregate with all imports, DBAL connection setup with multiple connections, Symfony Messenger channel configuration with `messenger.yaml`, DBAL-backed channels, multi-tenant setup with full `doctrine.yaml` for multiple entity managers, and Doctrine ORM entity mappings. Load when you need full working class files with imports and complete configuration examples.
