# Aggregate API Reference

## Aggregate Attribute

Source: `Ecotone\Api\Attribute\Aggregate`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class Aggregate {}
```

Class-level attribute. Marks a class as a state-stored aggregate.

## EventSourcingAggregate Attribute

Source: `Ecotone\Api\Attribute\EventSourcingAggregate`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class EventSourcingAggregate {}
```

Class-level attribute. Marks a class as an event-sourced aggregate. State is rebuilt from events via `#[EventSourcingHandler]` methods.

## Identifier Attribute

Source: `Ecotone\Api\Attribute\Identifier`

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Identifier
{
    public function __construct(public string $identifierPropertyName = '') {}
}
```

Parameters:
- `identifierPropertyName` (string) -- optional custom name for the identifier property. If empty, uses the property name.

Can be applied to properties or constructor parameters. Multiple `#[Identifier]` properties create a composite identifier.

## EventSourcingHandler Attribute

Source: `Ecotone\Api\Attribute\EventSourcingHandler`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class EventSourcingHandler {}
```

Method-level attribute. Marks a method that applies an event to rebuild aggregate state. These methods must have NO side effects -- only state assignment.

## Version Attribute

Source: `Ecotone\Api\Attribute\Version`

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
class Version {}
```

Property-level attribute. Marks the version property used for optimistic concurrency control. Typically used via the `WithAggregateVersioning` trait instead.

## WithAggregateVersioning Trait

Source: `Ecotone\Modelling\WithAggregateVersioning`

Provides automatic version tracking for event-sourced aggregates. Adds a version property with `#[Version]`.

```php
#[EventSourcingAggregate]
class MyAggregate
{
    use WithAggregateVersioning;
}
```

## WithEvents Trait

Source: `Ecotone\Modelling\WithEvents`

Allows state-stored aggregates to publish domain events.

```php
#[Aggregate]
class MyAggregate
{
    use WithEvents;

    public function doSomething(): void
    {
        $this->recordThat(new SomethingHappened($this->id));
    }
}
```

Methods:
- `recordThat(object $event)` -- records a domain event to be published after handler completes
- Events are auto-cleared after publishing

## TargetIdentifier Attribute

Source: `Ecotone\Api\Attribute\TargetIdentifier`

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TargetIdentifier {}
```

Applied to command/event properties to explicitly mark which property maps to the aggregate identifier.
