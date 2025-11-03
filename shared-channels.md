Summary: Shared Channels with Consumer Groups - Requirements, Problems, and Solutions

Use Cases for Shared Channels

Use Case 1: Distributed Bus with Service Map
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

Use Case 2: Projections (Multiple Consumer Groups per Application)
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