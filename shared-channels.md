Summary: Shared Channels with Consumer Groups - Requirements, Problems, and Solutions

Three Use Cases for Shared Channels

Use Case 1: Async Event Handlers (Single Application)
Goal: Do not allow to use shared channel for asynchronous handlers. Throw configuration exception stating asynchronous handlers working in point-to-point manner, therefore shared channels cannot be used, and to switch to standard channel.

Example:

```php
// Application: User Service
#[EventHandler(listenTo: 'UserRegistered')]
#[Asynchronous('events_channel')]
public function sendEmail(UserRegistered $event) { }

#[EventHandler(listenTo: 'UserRegistered')]
#[Asynchronous('events_channel')]
public function updateAnalytics(UserRegistered $event) { }
```

Use Case 2: Distributed Bus with Service Map
Goal: Publish events to shared channel, multiple applications consume with separate consumer groups.

Example:

```php
// Publisher (User Service)
$distributedBus->publishEvent('UserRegistered', $event);

// Consumer (Ticket Service)
#[EventHandler(listenTo: 'UserRegistered')]
public function createTicket(UserRegistered $event) { }

// Consumer (Order Service)
#[EventHandler(listenTo: 'UserRegistered')]
public function createOrder(UserRegistered $event) { }
```
Configuration:
```php
// User Service (Publisher)
DistributedServiceMap::initialize()
    ->withServiceMapping(
        serviceName: 'ticket-service',
        channelName: 'distributed_events'
    )
    ->withServiceMapping(
        serviceName: 'order-service',
        channelName: 'distributed_events'
    );

// Ticket Service defines channel with its own consumer group
AmqpStreamChannelBuilder::createShared(
    channelName: 'distributed_events',
    queueName: 'events_stream',
    defaultEndpointId: 'ticket-service'
);

// Order Service defines channel with its own consumer group
AmqpStreamChannelBuilder::createShared(
    channelName: 'distributed_events',
    queueName: 'events_stream',
    defaultEndpointId: 'order-service'
);
```


Requirements:

✅ Publisher defines shared channel
✅ Each application uses separate endpoint ID for tracking (separate message group)
✅ Multiple applications consume from one channel
✅ Each application executes all its event handlers together
✅ Routing slip should point to Event Bus, not specific handlers
Consumer Group ID: {serviceName}:{queueName} (e.g., ticket-service:events_stream, order-service:events_stream)

Use Case 3: Projections (Multiple Consumer Groups per Application)
Goal: Each projection runs as separate consumer group, allowing independent progress tracking.

Example:

```php
#[Projection(name: 'user_statistics', fromStreams: ['user_events'])]
#[Asynchronous('projection_channel')]
class UserStatisticsProjection { }

#[Projection(name: 'user_audit', fromStreams: ['user_events'])]
#[Asynchronous('projection_channel')]
class UserAuditProjection { }
```

Requirements:

✅ Each projection = separate consumer group (separate endpoint ID)
✅ Multiple message groups (endpoint IDs) on same application level
✅ Each projection tracks its own position independently
✅ Channel defined with default endpoint ID, but overridden per projection
Consumer Group ID: {projectionName}:{queueName} (e.g., user_statistics:events_stream, user_audit:events_stream)

Key Differences Between Use Cases
Aspect	Use Case 1	Use Case 2	Use Case 3
Consumer Groups per App	1	1	Multiple (one per projection)
Consumer Groups Total	1	Multiple (one per service)	Multiple (one per projection)
Endpoint ID Source	Application name	Service name (explicit)	Projection name (per endpoint)
Routing Slip Target	Event Bus	Event Bus	Projection Executor
Position Tracking	Application-level	Service-level	Projection-level
Proposed Solution: defaultEndpointId Parameter
API Design

```php
class AmqpStreamChannelBuilder
{
    public static function createShared(
        string $channelName,
        string $queueName,
        string $defaultEndpointId,  // NEW: Default consumer group identifier
        string $startPosition = 'first',
        string $amqpConnectionReferenceName = AmqpConnectionFactory::class
    ): self;
}
```

How It Solves Each Use Case
Use Case 1: Async Event Handlers

```php
#[ServiceContext]
public function asyncEventChannel(): AmqpStreamChannelBuilder
{
    return AmqpStreamChannelBuilder::createShared(
        channelName: 'events_channel',
        queueName: 'events_stream',
        defaultEndpointId: 'user-service'  // Application name
    );
}
```

Behavior:

Consumer ID: user-service:events_stream
All event handlers on this channel execute together
Routing slip: ['events_channel', 'ecotone.event_bus']
Use Case 2: Distributed Bus

```php
// Ticket Service
#[ServiceContext]
public function distributedChannel(): AmqpStreamChannelBuilder
{
    return AmqpStreamChannelBuilder::createShared(
        channelName: 'distributed_events',
        queueName: 'events_stream',
        defaultEndpointId: 'ticket-service'  // Service name
    );
}

// Order Service
#[ServiceContext]
public function distributedChannel(): AmqpStreamChannelBuilder
{
    return AmqpStreamChannelBuilder::createShared(
        channelName: 'distributed_events',
        queueName: 'events_stream',
        defaultEndpointId: 'order-service'  // Different service name
    );
}
```

Behavior:

Ticket Service Consumer ID: ticket-service:events_stream
Order Service Consumer ID: order-service:events_stream
Each service consumes independently
Routing slip: ['distributed_events', 'ecotone.event_bus']
Use Case 3: Projections

```php
#[ServiceContext]
public function projectionChannel(): AmqpStreamChannelBuilder
{
    return AmqpStreamChannelBuilder::createShared(
        channelName: 'projection_channel',
        queueName: 'events_stream',
        defaultEndpointId: 'default-projection-group'  // Fallback, usually overridden
    );
}

// In ProjectingModule, override per projection:
$messagingConfiguration->registerAsynchronousEndpoint(
    channelName: 'projection_channel',
    endpointId: 'user_statistics'  // Override: this projection's consumer group
);

$messagingConfiguration->registerAsynchronousEndpoint(
    channelName: 'projection_channel',
    endpointId: 'user_audit'  // Override: different consumer group
);
```

Behavior:

User Statistics Consumer ID: user_statistics:events_stream
User Audit Consumer ID: user_audit:events_stream
Each projection tracks position independently
Routing slip: ['projection_channel', 'user_statistics.target'] (per projection)
Implementation Details
1. Consumer ID Generation

```php
// In AmqpStreamInboundChannelAdapter
private function getConsumerId(): string
{
    // If endpoint ID is explicitly set (e.g., for projections), use it
    // Otherwise, use defaultEndpointId from channel builder
    $effectiveEndpointId = $this->endpointId ?? $this->defaultEndpointId;

    return $effectiveEndpointId . ':' . $this->queueName;
}
```

2. Routing Slip Logic
```php
// In MessagingSystemConfiguration (RoutingSlipPrepender)
if ($this->channelBuilders[$asynchronousMessageChannel] instanceof AmqpStreamChannelBuilder
    && $this->channelBuilders[$asynchronousMessageChannel]->isSharedChannel()) {

    if ($annotationForMethod instanceof EventHandler) {
        // For shared channels with event handlers, route to Event Bus
        $consequentialChannels = [
            $asynchronousMessageChannel,
            MessageBusChannel::EVENT_CHANNEL_NAME_BY_NAME
        ];
    } else {
        // For projections or other handlers, route to specific handler
        $consequentialChannels = [
            $asynchronousMessageChannel,
            $handlerExecutionChannel
        ];
    }
} else {
    // Non-shared channels keep current behavior
    $consequentialChannels = [
        $asynchronousMessageChannel,
        $handlerExecutionChannel
    ];
}
```

3. Channel Builder Changes

```php
class AmqpStreamChannelBuilder
{
    private ?string $defaultEndpointId = null;

    public static function createShared(
        string $channelName,
        string $queueName,
        string $defaultEndpointId,
        string $startPosition = 'first',
        string $amqpConnectionReferenceName = AmqpConnectionFactory::class
    ): self {
        $instance = new self($channelName, $queueName, $startPosition, $amqpConnectionReferenceName);
        $instance->defaultEndpointId = $defaultEndpointId;
        return $instance;
    }

    public function isSharedChannel(): bool
    {
        return $this->defaultEndpointId !== null;
    }

    public function getDefaultEndpointId(): ?string
    {
        return $this->defaultEndpointId;
    }
}
```