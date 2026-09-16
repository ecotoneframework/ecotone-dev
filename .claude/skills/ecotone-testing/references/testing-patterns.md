# Testing Patterns -- Async, Projections, and Debugging

## Async-Tested-Synchronously Pattern

```php
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;

public function test_async_event_processing(): void
{
    $handler = new class {
        public int $processedCount = 0;

        #[Asynchronous('notifications')]
        #[EventHandler(endpointId: 'notificationHandler')]
        public function handle(OrderWasPlaced $event): void
        {
            $this->processedCount++;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->publishEvent(new OrderWasPlaced('order-1'));

    // Not yet processed
    $this->assertEquals(0, $handler->processedCount);

    // Run the consumer (the in-memory 'notifications' channel is provided by flow testing)
    $ecotone->run('notifications');

    // Now processed
    $this->assertEquals(1, $handler->processedCount);
}
```

## Projection Testing with Event Store

```php
public function test_projection_builds_read_model(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        classesToResolve: [TicketListProjection::class, Ticket::class],
        containerOrAvailableServices: [new TicketListProjection()],
    );

    $ecotone->initializeProjection('ticket_list');

    $ecotone->sendCommand(new RegisterTicket('t-1', 'Bug report'));
    $ecotone->triggerProjection('ticket_list');

    $result = $ecotone->sendQueryWithRouting('getTickets');
    $this->assertCount(1, $result);
}
```

### Projection Lifecycle in Tests

```php
$ecotone->initializeProjection('name');  // Setup
$ecotone->triggerProjection('name');     // Process events
$ecotone->resetProjection('name');       // Clear + reinit
$ecotone->deleteProjection('name');      // Cleanup
```

## ServiceConfiguration with ModulePackageList

```php
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;

public function test_with_dbal_module(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [MyProjection::class],
        configuration: ServiceConfiguration::createWithDefaults()
            ->withModulePackages([
                ModulePackageList::DBAL_PACKAGE,
                ModulePackageList::EVENT_SOURCING_PACKAGE,
            ]),
    );
}
```

## Debugging Test Failures

| Symptom | Cause | Fix |
|---------|-------|-----|
| "No handler found for message" | Handler class not in `classesToResolve` | Add class to first argument |
| "Service not found in container" | Missing dependency | Add to `containerOrAvailableServices` |
| "It is handled by X::y(), which is not registered in this Ecotone Lite bootstrap" | Handler class not in `classesToResolve` | Add the named class or load its namespace |
| "Cannot move time backwards" | `changeTimeTo()` earlier than the test clock | Use a later time or `advanceTimeBy()` |
| Message not processed | Async handler not run | Call `$ecotone->run('channelName')` |
| "Module not found" | Wrong `ModulePackageList` config | Check `withModulePackages([...])` lists the needed packages |
| Database errors | Missing DSN env vars | Run inside Docker container with env vars set |
| Lowest dependency failures | API differences between versions | Test both `--prefer-lowest` and latest |

## Common Mistakes

- **Don't** use raw PHPUnit mocking instead of EcotoneLite -- use the framework's test support
- **Don't** create separate fixture class files for test-only handlers -- use inline anonymous classes
- **Don't** test implementation details -- test behavior from the end-user perspective
- **Don't** forget to call `->run('channel')` for async handlers -- messages won't process otherwise
- **Don't** mix `bootstrapFlowTesting` and `bootstrapFlowTestingWithEventStore` -- pick the right one
