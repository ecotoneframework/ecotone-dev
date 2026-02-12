# Event Versioning Patterns Reference

## Revision Attribute

Source: `Ecotone\Modelling\Attribute\Revision`

Mark events with a version number for schema evolution:

```php
use Ecotone\Modelling\Attribute\Revision;

// Version 1 (default when no attribute)
class PersonWasRegistered
{
    public function __construct(
        public readonly string $personId,
        public readonly string $name,
    ) {}
}

// Version 2 — added 'type' field
#[Revision(2)]
class PersonWasRegistered
{
    public function __construct(
        public readonly string $personId,
        public readonly string $name,
        public readonly string $type,  // new in v2
    ) {}
}
```

- Default revision is 1 when no `#[Revision]` attribute
- Stored in message metadata as `MessageHeaders::REVISION`
- Access in handlers: `#[Header(MessageHeaders::REVISION)] int $revision`

## Named Events

Source: `Ecotone\Modelling\Attribute\NamedEvent`

Decouple class name from stored event type:

```php
use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('ticket.was_registered')]
class TicketWasRegistered
{
    public function __construct(
        public readonly string $ticketId,
        public readonly string $type,
    ) {}
}
```

Benefits:
- Rename or move event classes without breaking stored events
- Consistent event naming across services
- Enables polyglot event consumption

## Upcasting Pattern

Upcasters transform old event versions to the current schema:

```php
use Ecotone\Modelling\Attribute\EventRevision;

class PersonWasRegisteredUpcaster
{
    // Transform v1 events to v2 shape
    public function upcast(array $payload, int $revision): array
    {
        if ($revision < 2) {
            $payload['type'] = 'default';  // Provide default for new field
        }
        return $payload;
    }
}
```

## Event Schema Evolution Strategies

### 1. Adding Fields (Backward Compatible)

Add new fields with defaults in the upcaster:

```php
// v1: { personId, name }
// v2: { personId, name, type }
// Upcaster sets type='default' for v1 events
```

### 2. Renaming Fields

Map old names to new in the upcaster:

```php
public function upcast(array $payload, int $revision): array
{
    if ($revision < 2) {
        $payload['fullName'] = $payload['name'];
        unset($payload['name']);
    }
    return $payload;
}
```

### 3. Splitting Events

Transform one old event into multiple new events:

```php
// v1: PersonWasRegisteredAndActivated { id, name, activatedAt }
// v2: Split into PersonWasRegistered + PersonWasActivated
```

### 4. Removing Fields

Upcaster strips deprecated fields:

```php
public function upcast(array $payload, int $revision): array
{
    unset($payload['deprecatedField']);
    return $payload;
}
```

## Best Practices

1. **Always increment revision** when changing event schema
2. **Never modify stored events** — transform on read via upcasters
3. **Use `#[NamedEvent]`** to decouple storage from class names
4. **Add defaults in upcasters** for new required fields
5. **Keep events immutable** — all properties `readonly`
6. **Version from the start** — use `#[Revision(1)]` explicitly
7. **Test upcasters** — verify old events can be loaded with new code

## Testing Versioned Events

```php
public function test_old_event_version_is_upcasted(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        classesToResolve: [Person::class, PersonWasRegisteredUpcaster::class],
    );

    // Store v1 event (raw)
    $ecotone->withEventsFor('person-1', Person::class, [
        new PersonWasRegisteredV1('person-1', 'John'),
    ]);

    // Command handler works with v2 shape
    $person = $ecotone->getAggregate(Person::class, 'person-1');
    $this->assertEquals('default', $person->getType());
}
```

## Dynamic Consistency Boundary (DCB)

DCB allows multiple aggregates to share consistency guarantees without distributed transactions:

- Events from multiple aggregates can be read in a single projection
- Projection state provides the consistency boundary
- Use multi-stream projections (`#[FromStream]` on multiple aggregate types)
- Decision models can load events from multiple streams to make consistent decisions

```php
#[ProjectionV2('inventory_consistency')]
#[FromStream(Order::class)]
#[FromStream(Warehouse::class)]
class InventoryConsistencyProjection
{
    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Check inventory consistency across aggregates
    }

    #[EventHandler]
    public function onStockUpdated(StockWasUpdated $event): void
    {
        // Update inventory view
    }
}
```
