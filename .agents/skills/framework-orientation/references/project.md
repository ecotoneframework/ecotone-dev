## Project Overview

Ecotone is the enterprise architecture layer for Laravel and Symfony.
One Composer package adds CQRS, Event Sourcing, Sagas, Projections, Workflows, and Outbox messaging via declarative
PHP 8 attributes.
Works with Symfony, Laravel, Tempest, or standalone via Ecotone Lite (any PSR-11 container).

## Monorepo Structure

Every directory under `packages/` is a separate Composer package with its own `composer.json`, `phpstan.neon`,
`phpunit.xml.dist` and `vendor/`, split to a read-only repository on release:

| | |
|---|---|
| `Ecotone` | the core package — every other package depends on it |
| `Dbal`, `PdoEventSourcing` | database abstraction; event sourcing and the event store |
| `Amqp`, `Sqs`, `Redis`, `Kafka`, `Enqueue` | broker and queue integrations |
| `Laravel`, `Symfony`, `Tempest` | framework integrations |
| `JmsConverter`, `OpenTelemetry`, `DataProtection` | serialization, tracing, encryption |

- **A change to `packages/Ecotone` can affect every package**, so run the downstream suites too, and a
  cross-package change needs tests in both packages
- Splits are managed by `symplify/monorepo-builder` (`monorepo-builder.php`)
- Template for a new package: `_PackageTemplate/`. Registering it also means adding its name to
  `ModulePackageList` (`Ecotone\Api\ExtensionObject\ModulePackageList`), to `ModuleClassList::packageNames()` and
  `ModuleClassList::forPackage()`, and its module class to the right `ModuleClassList` constant

## Architecture Patterns

- **Messages first** - Commands, Events, Queries are first-class citizens
- **Declarative configuration** - PHP attributes and `#[ServiceContext]`, never YAML/XML
- **`#[InternalHandler]`** - for a framework-internal message handler (`#[ServiceActivator]` was removed in 2.0)
- **`InterfaceToCall`** / **`ClassDefinition`** - method and class metadata, obtained from the memoizing
  `InterfaceToCallRegistry` that `Module::prepare()` receives — never a fresh `ReflectionClass`
  ([rule 13a](../../framework-service-design/references/rules.md#13a-class-metadata-comes-from-the-registry-not-from-fresh-reflection))
- **`MessageHeaders`** (`Ecotone\Api\Messaging\MessageHeaders`) - for message metadata propagation
- **`Definition` / `DefinedObject`** - configuration is compiled into a container, so what a module registers must
  be expressible as a `Definition`
- **Modules** - implement `AnnotationModule`, carry `#[ModuleAnnotation]`, and are registered **explicitly**: the
  module class in the right `ModuleClassList` constant, and a new package's name in `ModulePackageList`

## Common Patterns

Every user-facing class lives under `Ecotone\Api` — attributes in `Ecotone\Api\Attribute`, buses in
`Ecotone\Api\Gateway` (`CommandBus`, `EventBus`, `QueryBus`, `DistributedBus`), projections in
`Ecotone\Api\Projecting`. The imports are part of the pattern: the namespaces moved in 2.0, so check them against
the tree rather than from memory (`upgrade/namespace-map-2.0.csv` has the full mapping).

### Handlers
```php
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

final class OrderService
{
    #[CommandHandler]
    public function place(PlaceOrder $command): void
    {
    }

    #[EventHandler]
    public function whenPlaced(OrderPlaced $event): void
    {
    }

    #[Asynchronous('orders')]
    #[EventHandler]
    public function notifyCustomer(OrderPlaced $event): void
    {
    }

    #[QueryHandler('order.status')]
    public function status(string $orderId): string
    {
        return $this->statuses[$orderId];
    }
}
```

### Aggregate
```php
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;

#[Aggregate]
final class Order
{
    private function __construct(#[Identifier] private string $orderId)
    {
    }

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        return new self($command->orderId);
    }
}
```

### Programmatic configuration
```php
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true);
    }
}
```

## Documentation Resources

In this repository:

- `.agents/skills/framework-*` - how this repository is developed; [framework-orientation](../SKILL.md) maps each kind of work to its skill
- `upgrade-2.0.md` - every 1.x → 2.0 behaviour change, with Before / Now / How to adapt
- `upgrade/namespace-map-2.0.csv` - the full old → new FQCN mapping for the 2.0 `Api` move
- `docs/superpowers/specs/` - design decisions, the alternatives considered, and why. Untracked here:
  `docs/superpowers/` is in `.gitignore`, and the records live on branch `dgafka/dcb-docs`
- `api/skills/ecotone-*` - per-area guides for Ecotone users on the user-facing API, split to
  `ecotoneframework/skills` on release; never loaded while developing the framework

Published:

- [Full Documentation](https://docs.ecotone.tech)
- [Testing Support](https://docs.ecotone.tech/modelling/testing-support)
- [Contributing Guide](https://docs.ecotone.tech/messaging/contributing-to-ecotone)
- [Blog & Examples](https://blog.ecotone.tech)

