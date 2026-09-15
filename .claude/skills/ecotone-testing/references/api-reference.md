# EcotoneLite & FlowTestSupport API Reference

## EcotoneLite Bootstrap Methods

### `EcotoneLite::bootstrapFlowTesting()`

Standard test bootstrap. Skips all module packages by default.

```php
public static function bootstrapFlowTesting(
    array                    $classesToResolve = [],
    ContainerInterface|array $containerOrAvailableServices = [],
    ?ServiceConfiguration    $configuration = null,
    array                    $configurationVariables = [],
    ?string                  $pathToRootCatalog = null,
    bool                     $allowGatewaysToBeRegisteredInContainer = false,
    bool                     $addInMemoryStateStoredRepository = true,
    bool                     $addInMemoryEventSourcedRepository = true,
    ?TestConfiguration       $testConfiguration = null,
    ?string                  $licenceKey = null
): FlowTestSupport
```

### `EcotoneLite::bootstrapFlowTestingWithEventStore()`

Test bootstrap with in-memory event store. Enables eventSourcing, dbal, jmsConverter packages.

```php
public static function bootstrapFlowTestingWithEventStore(
    array                    $classesToResolve = [],
    ContainerInterface|array $containerOrAvailableServices = [],
    ?ServiceConfiguration    $configuration = null,
    array                    $configurationVariables = [],
    ?string                  $pathToRootCatalog = null,
    bool                     $allowGatewaysToBeRegisteredInContainer = false,
    bool                     $addInMemoryStateStoredRepository = true,
    bool                     $runForProductionEventStore = false,
    ?TestConfiguration       $testConfiguration = null,
    ?string                  $licenceKey = null,
): FlowTestSupport
```

### `EcotoneLite::bootstrapFlowTesting()`

Low-level bootstrap with full control. Does not skip any packages automatically.

```php
public static function bootstrapFlowTesting(
    array                    $classesToResolve = [],
    ContainerInterface|array $containerOrAvailableServices = [],
    ?ServiceConfiguration    $configuration = null,
    array                    $configurationVariables = [],
    ?string                  $pathToRootCatalog = null,
    bool                     $allowGatewaysToBeRegisteredInContainer = false,
    ?string                  $licenceKey = null,
): FlowTestSupport
```

## FlowTestSupport Methods

### Sending Messages

| Method | Description |
|--------|-------------|
| `sendCommand(object $command, array $metadata = [])` | Send command object |
| `sendCommandWithRoutingKey(string $routingKey, mixed $command = [], ...)` | Send command by routing key |
| `publishEvent(object $event, array $metadata = [])` | Publish event object |
| `publishEventWithRoutingKey(string $routingKey, mixed $event = [], ...)` | Publish event by routing key |
| `sendQuery(object $query, array $metadata = [], ...)` | Send query, returns result |
| `sendQueryWithRouting(string $routingKey, mixed $query = [], ...)` | Send query by routing key |
| `sendDirectToChannel(string $channel, mixed $payload = '', array $metadata = [])` | Send directly to channel |

### Recorded Messages

| Method | Returns | Description |
|--------|---------|-------------|
| `popRecordedEvents()` | `mixed[]` | Events published via EventBus since the last pop; removes them |
| `popRecordedEventsOfType(string $className)` | `object[]` | Removes and returns only events of that class |
| `popRecordedEventHeaders()` | `MessageHeaders[]` | Headers of recorded events |
| `popRecordedCommands()` | `mixed[]` | Commands sent via CommandBus since the last pop; removes them |
| `popRecordedCommandsOfType(string $className)` | `object[]` | Removes and returns only commands of that class |
| `popRecordedCommandHeaders()` | `MessageHeaders[]` | Headers of recorded commands |
| `popRecordedCommandsWithRouting()` | `string[]` | Commands with routing keys |
| `popRecordedMessagePayloadsFrom(string $channelName)` | `mixed[]` | Payloads from specific channel |
| `popRecordedMessagesFrom(string $channelName)` | `Message[]` | Full messages from channel |
| `discardRecordedMessages()` | `self` | Clear all recorded messages |

### Aggregate & Saga State

| Method | Returns | Description |
|--------|---------|-------------|
| `getAggregate(string $class, string\|int\|array\|object $ids)` | `object` | Load aggregate by ID |
| `getSaga(string $class, string\|array $ids)` | `object` | Load saga by ID |
| `withEventsFor(string\|object\|array $ids, string $class, array $events, int $version = 0)` | `self` | Set up event-sourced aggregate state |
| `withStateFor(object $aggregate)` | `self` | Set up state-stored aggregate |

### Event Store

| Method | Returns | Description |
|--------|---------|-------------|
| `withEventStream(string $streamName, array $events)` | `self` | Append events to named stream |
| `withEvents(array $events)` | `self` | Append events to default stream |
| `deleteEventStream(string $streamName)` | `self` | Delete event stream |
| `getEventStreamEvents(string $streamName)` | `Event[]` | Load events from stream |

### Async Processing

| Method | Returns | Description |
|--------|---------|-------------|
| `run(string $name, ?ExecutionPollingMetadata $meta = null)` | `self` | Run consumer/endpoint; delivers messages due at the test clock's current time |
| `getMessageChannel(string $channelName)` | `MessageChannel` | Get channel instance |
| `receiveMessageFrom(string $channelName)` | `?Message` | Receive from pollable channel |

### Projections

| Method | Returns | Description |
|--------|---------|-------------|
| `initializeProjection(string $name, array $metadata = [])` | `self` | Initialize projection |
| `triggerProjection(string\|array $name)` | `self` | Trigger projection catch-up |
| `resetProjection(string $name)` | `self` | Reset projection (clear + reinit) |
| `deleteProjection(string $name)` | `self` | Delete projection |
| `stopProjection(string $name)` | `self` | Stop projection |

### Time Control

| Method | Returns | Description |
|--------|---------|-------------|
| `changeTimeTo(DateTimeImmutable $time)` | `self` | Set clock to specific time (same or later instant) |
| `advanceTimeBy(TimeSpan\|Duration $span)` | `self` | Move clock forward by span; calls add up |

### Infrastructure

| Method | Returns | Description |
|--------|---------|-------------|
| `getGateway(string $gatewayClass)` | `object` | Get gateway instance |
| `getServiceFromContainer(string $serviceId)` | `object` | Get service from container |
| `getMessagingSystem()` | `ConfiguredMessagingSystem` | Get messaging system |
