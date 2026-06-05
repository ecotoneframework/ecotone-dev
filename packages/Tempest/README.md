# This is Read Only Repository
To contribute make use of [Ecotone-Dev repository](https://github.com/ecotoneframework/ecotone-dev).

<p align="left"><a href="https://ecotone.tech" target="_blank">
    <img src="https://github.com/ecotoneframework/ecotone-dev/blob/main/ecotone_small.png?raw=true">
</a></p>

![Github Actions](https://github.com/ecotoneFramework/ecotone-dev/actions/workflows/split-testing.yml/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/ecotone/tempest/v/stable)](https://packagist.org/packages/ecotone/tempest)
[![License](https://poser.pugx.org/ecotone/tempest/license)](https://packagist.org/packages/ecotone/tempest)
[![Total Downloads](https://img.shields.io/packagist/dt/ecotone/tempest)](https://packagist.org/packages/ecotone/tempest)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/ecotone/tempest/php.svg)](https://packagist.org/packages/ecotone/tempest)

**Ecotone is the PHP architecture layer that grows with your system — without rewrites.**

From `#[CommandHandler]` on day one, to event sourcing, sagas, outbox, and distributed messaging at scale — one package, same codebase, no forced migrations between growth stages. Declarative PHP 8 attributes on the classes you already have.

## ecotone/tempest

Ecotone for [Tempest](https://tempestphp.com) — CQRS, Event Sourcing, Sagas, Durable Workflows, and Outbox via PHP attributes. Zero-config auto-discovery derives your application namespaces from your `composer.json` PSR-4 roots. Handlers, aggregates, sagas, and projections are found automatically without any registration boilerplate.

- Zero-config auto-discovery of handlers from your app's PSR-4 namespaces
- CQRS — `CommandBus`, `QueryBus`, `EventBus` available via dependency injection
- Database integration via `ecotone/dbal` with Tempest's `DatabaseConfig`
- Multi-tenant connections with per-tenant database switching
- Async messaging, sagas, outbox, event sourcing (with appropriate modules)
- Console commands: `ecotone:list`, `ecotone:run`, `ecotone:cache:clear`

Visit [ecotone.tech](https://ecotone.tech) to learn more.

## Installation

```bash
composer require ecotone/tempest
```

Ecotone auto-discovery is enabled via Tempest's package discovery system. No service provider registration is needed.

## Getting Started

### Zero-Config Handler Discovery

Ecotone derives your application namespaces from the PSR-4 roots declared in your `composer.json`. Any class with an Ecotone attribute (`#[CommandHandler]`, `#[QueryHandler]`, `#[EventHandler]`, etc.) in those namespaces is discovered automatically.

```php
// src/Order/PlaceOrderHandler.php
namespace App\Order;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class PlaceOrderHandler
{
    private array $orders = [];

    #[CommandHandler('order.place')]
    public function place(string $orderId): void
    {
        $this->orders[] = $orderId;
    }

    #[QueryHandler('order.all')]
    public function all(): array
    {
        return $this->orders;
    }
}
```

That is all that is needed. No configuration file, no registration — Ecotone discovers it from your namespace.

### Using the Buses

`CommandBus`, `QueryBus`, and `EventBus` are automatically registered in the Tempest container and can be injected anywhere:

```php
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;

final class OrderController
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function place(string $orderId): array
    {
        $this->commandBus->sendWithRouting('order.place', $orderId);

        return $this->queryBus->sendWithRouting('order.all');
    }
}
```

## Optional Configuration

Create a class that provides `EcotoneConfig` to the Tempest container to customise behaviour:

```php
// src/Configuration/EcotoneConfiguration.php
namespace App\Configuration;

use Ecotone\Tempest\EcotoneConfig;
use Tempest\Container\Singleton;

#[Singleton]
final class EcotoneConfiguration
{
    public function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            serviceName: 'my-service',
            licenceKey: getenv('ECOTONE_LICENCE_KEY') ?: '',
            cacheConfiguration: true,
        );
    }
}
```

Or simply bind `EcotoneConfig` in a Tempest config file:

```php
// config/ecotone.php  (or any #[ServiceContext] provider)
use Ecotone\Tempest\EcotoneConfig;

return new EcotoneConfig(
    serviceName: 'my-service',
    licenceKey: getenv('ECOTONE_LICENCE_KEY') ?: '',
);
```

### `EcotoneConfig` Reference

| Property | Type | Default | Description |
|---|---|---|---|
| `serviceName` | `string` | `''` (from `ECOTONE_SERVICE_NAME` env) | Identifies this service in distributed tracing and logs |
| `namespaces` | `array` | `[]` | Explicit namespaces to scan. When empty and `loadAppNamespaces` is true, derived from `composer.json` |
| `loadAppNamespaces` | `bool` | `true` | Auto-derive scan namespaces from your app's PSR-4 roots |
| `cacheConfiguration` | `bool` | `false` (from `ECOTONE_CACHE_CONFIGURATION` env) | Cache the messaging system definition for production |
| `defaultSerializationMediaType` | `string` | `''` | Override the default message serialization format |
| `defaultErrorChannel` | `string` | `''` | Channel name for unhandled async exceptions |
| `skippedModulePackageNames` | `array` | `[]` | Module packages to skip loading (useful for testing) |
| `licenceKey` | `string` | `''` | Enterprise licence key |

## Console Commands

Ecotone registers Tempest console commands automatically:

```bash
# List all registered consumers and handlers
./tempest ecotone:list

# Run an asynchronous consumer (requires ecotone/dbal or another async transport)
./tempest ecotone:run notifications

# Clear the Ecotone configuration cache
./tempest ecotone:cache:clear
```

The `ecotone:cache:clear` command removes the cached messaging system definition from `sys_get_temp_dir()/ecotone_tempest/`. Use it after deploying changes when `cacheConfiguration` is enabled.

## Database Integration (requires `ecotone/dbal`)

Install the DBAL module:

```bash
composer require ecotone/dbal
```

### Single-Tenant Connection

Register a `TempestConnectionReference` via `#[ServiceContext]` to bridge Tempest's `DatabaseConfig` to Ecotone's DBAL module:

```php
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Tempest\Config\TempestConnectionReference;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbalConnection(): TempestConnectionReference
    {
        return TempestConnectionReference::defaultConnection();
    }
}
```

`TempestConnectionReference::defaultConnection()` resolves Tempest's default `DatabaseConfig` from the container at runtime.

To use a specific config:

```php
use Tempest\Database\Config\PostgresConfig;

#[ServiceContext]
public function dbalConnection(): TempestConnectionReference
{
    return TempestConnectionReference::create('myConnection', new PostgresConfig(
        host: getenv('DB_HOST') ?: 'localhost',
        port: getenv('DB_PORT') ?: '5432',
        username: getenv('DB_USER') ?: 'app',
        password: getenv('DB_PASSWORD') ?: '',
        database: getenv('DB_NAME') ?: 'app',
    ));
}
```

### Multi-Tenant Connection

Use `MultiTenantConfiguration` together with per-tenant `TempestConnectionReference` instances. A `tenant` header on each message selects the correct database:

```php
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Tempest\Config\TempestConnectionReference;
use Tempest\Database\Config\MysqlConfig;
use Tempest\Database\Config\PostgresConfig;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function multiTenantConfiguration(): MultiTenantConfiguration
    {
        return MultiTenantConfiguration::create(
            tenantHeaderName: 'tenant',
            tenantToConnectionMapping: [
                'tenant_a' => TempestConnectionReference::create('tenant_a', new PostgresConfig(
                    host: getenv('TENANT_A_DB_HOST'),
                    username: getenv('TENANT_A_DB_USER'),
                    password: getenv('TENANT_A_DB_PASSWORD'),
                    database: getenv('TENANT_A_DB_NAME'),
                )),
                'tenant_b' => TempestConnectionReference::create('tenant_b', new MysqlConfig(
                    host: getenv('TENANT_B_DB_HOST'),
                    username: getenv('TENANT_B_DB_USER'),
                    password: getenv('TENANT_B_DB_PASSWORD'),
                    database: getenv('TENANT_B_DB_NAME'),
                )),
            ],
        );
    }
}
```

Route commands to the correct tenant by setting the header:

```php
$commandBus->sendWithRouting('order.place', $order, metadata: ['tenant' => 'tenant_a']);
```

> **Security note:** When `cacheConfiguration` is enabled, the `DatabaseConfig` for each
> `TempestConnectionReference::create(name, config)` call is serialized into the on-disk cache
> file. This means database credentials (username, password, DSN) are written to disk in
> base64-encoded serialized form. Keep the cache directory (`sys_get_temp_dir()/ecotone_tempest/`)
> non-world-readable and rotate credentials if the cache file is exposed. Use
> `./tempest ecotone:cache:clear` after credential rotation.

## Production Caching

Enable the configuration cache for production to avoid re-scanning annotations on every request:

```php
new EcotoneConfig(
    cacheConfiguration: true,
);
```

Or set the `ECOTONE_CACHE_CONFIGURATION=1` environment variable. The cache is stored in `sys_get_temp_dir()/ecotone_tempest/`. Clear it after deployment:

```bash
./tempest ecotone:cache:clear
```

When `APP_ENV=prod` or `APP_ENV=production`, the production cache path is used automatically regardless of the `cacheConfiguration` setting.

## Expression Language

Ecotone supports Symfony Expression Language in `#[Payload]` and `#[Header]` attributes. The `parameter()` function reads from environment variables via `TempestConfigurationVariableService`:

```php
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\CommandHandler;

final class CalculatorHandler
{
    #[CommandHandler('calculator.multiply')]
    public function multiply(
        #[Payload("parameter('APP_MULTIPLIER') * payload['value']")] int $result
    ): void {
        // $result = APP_MULTIPLIER * payload['value']
    }
}
```

Requires `symfony/expression-language`:

```bash
composer require symfony/expression-language
```

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

PHP, Ecotone, Tempest, CQRS, Event Sourcing, Sagas, Durable Workflows, Outbox, Messaging, EIP, DDD
