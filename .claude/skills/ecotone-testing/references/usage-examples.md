# Testing Usage Examples

## Event Handler Testing

```php
public function test_event_handler_reacts_to_event(): void
{
    $handler = new class {
        public array $receivedEvents = [];

        #[EventHandler]
        public function onOrderPlaced(OrderWasPlaced $event): void
        {
            $this->receivedEvents[] = $event;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->publishEvent(new OrderWasPlaced('order-1'));
    $this->assertCount(1, $handler->receivedEvents);
}
```

## Query Handler Testing

```php
public function test_query_returns_result(): void
{
    $handler = new class {
        #[QueryHandler]
        public function getOrder(GetOrder $query): array
        {
            return ['orderId' => $query->orderId, 'status' => 'placed'];
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $result = $ecotone->sendQuery(new GetOrder('order-1'));
    $this->assertEquals('placed', $result['status']);
}
```

## Command Handler with Inline Class

```php
public function test_command_handler_receives_command(): void
{
    $handler = new class {
        public ?PlaceOrder $receivedCommand = null;

        #[CommandHandler]
        public function handle(PlaceOrder $command): void
        {
            $this->receivedCommand = $command;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->sendCommand(new PlaceOrder('order-1'));
    $this->assertNotNull($handler->receivedCommand);
    $this->assertEquals('order-1', $handler->receivedCommand->orderId);
}
```

## State-Stored Aggregate Testing

```php
public function test_aggregate_creation_and_action(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone->sendCommand(new PlaceOrder('order-1', 'Widget'));

    $order = $ecotone->getAggregate(Order::class, 'order-1');
    $this->assertEquals('Widget', $order->getProduct());

    $ecotone->sendCommand(new CancelOrder('order-1'));
    $order = $ecotone->getAggregate(Order::class, 'order-1');
    $this->assertTrue($order->isCancelled());
}
```

## Event-Sourced Aggregate Testing

```php
public function test_event_sourced_aggregate(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

    // Set up initial state via events
    $events = $ecotone
        ->withEventsFor('ticket-1', Ticket::class, [
            new TicketWasRegistered('ticket-1', 'Bug', 'johny'),
        ])
        ->sendCommand(new CloseTicket('ticket-1'))
        ->getRecordedEvents();

    $this->assertEquals([new TicketWasClosed('ticket-1')], $events);
}
```

## Service Stubs and Dependencies

```php
public function test_handler_with_dependencies(): void
{
    $notifier = new class implements Notifier {
        public array $notifications = [];
        public function send(string $message): void
        {
            $this->notifications[] = $message;
        }
    };

    $handler = new class($notifier) {
        public function __construct(private Notifier $notifier) {}

        #[CommandHandler]
        public function handle(PlaceOrder $command): void
        {
            $this->notifier->send("Order {$command->orderId} placed");
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->sendCommand(new PlaceOrder('123'));
    $this->assertCount(1, $notifier->notifications);
}
```

## Recorded Messages Inspection

```php
public function test_inspect_recorded_messages(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone->sendCommand(new PlaceOrder('order-1'));

    // Get recorded events (published via EventBus)
    $events = $ecotone->getRecordedEvents();

    // Get recorded commands (sent via CommandBus)
    $commands = $ecotone->getRecordedCommands();

    // Get event headers
    $headers = $ecotone->getRecordedEventHeaders();

    // Discard and start fresh
    $ecotone->discardRecordedMessages();
}
```

## ModulePackageList Configuration

```php
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;

// Available package constants:
// ModulePackageList::CORE_PACKAGE
// ModulePackageList::ASYNCHRONOUS_PACKAGE
// ModulePackageList::AMQP_PACKAGE
// ModulePackageList::DBAL_PACKAGE
// ModulePackageList::REDIS_PACKAGE
// ModulePackageList::SQS_PACKAGE
// ModulePackageList::KAFKA_PACKAGE
// ModulePackageList::EVENT_SOURCING_PACKAGE
// ModulePackageList::JMS_CONVERTER_PACKAGE
// ModulePackageList::TRACING_PACKAGE
// ModulePackageList::TEST_PACKAGE

$config = ServiceConfiguration::createWithDefaults()
    ->withSkippedModulePackageNames(
        ModulePackageList::allPackagesExcept([
            ModulePackageList::DBAL_PACKAGE,
            ModulePackageList::EVENT_SOURCING_PACKAGE,
        ])
    );
```

## Projection Testing with Inline Class

```php
public function test_projection_builds_read_model(): void
{
    $projection = new class {
        public array $tickets = [];

        #[ProjectionInitialization]
        public function init(): void
        {
            $this->tickets = [];
        }

        #[EventHandler]
        public function onTicketRegistered(TicketWasRegistered $event): void
        {
            $this->tickets[] = ['id' => $event->ticketId, 'type' => $event->type];
        }

        #[QueryHandler('getTickets')]
        public function getTickets(): array
        {
            return $this->tickets;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        classesToResolve: [$projection::class, Ticket::class],
        containerOrAvailableServices: [$projection],
    );

    $ecotone->initializeProjection('ticket_list');
    $ecotone->sendCommand(new RegisterTicket('t-1', 'Bug'));
    $ecotone->triggerProjection('ticket_list');

    $result = $ecotone->sendQueryWithRouting('getTickets');
    $this->assertCount(1, $result);
}
```
