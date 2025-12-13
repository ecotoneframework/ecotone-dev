# Message Broker with Ecotone - RabbitMQ, Kafka, SQS, Redis

This example demonstrates how incredibly easy it is to set up **asynchronous messaging** with a message broker using [Ecotone Framework](https://ecotone.tech). With just a few lines of code, you can publish and consume messages from **RabbitMQ**, and switch to **Kafka**, **Amazon SQS**, **Redis**, or **Database (DBAL)** with a single line change.

## Quick Start

```bash
# Install dependencies
composer install

# Run publisher (sends message to RabbitMQ)
php publisher.php

# Run consumer (receives and processes message)
php consumer.php
```

**Output:**
```
Message sent to queue 'orders'
Starting consumer for queue 'orders'...
Processing order 123: Milk
Consumer finished.
```

## Why Ecotone Makes It Easy

### 1. Single Line Channel Configuration

Setting up a RabbitMQ-backed message channel requires just **one line**:

```php
AmqpBackedMessageChannelBuilder::create('orders')
```

That's it. No complex queue bindings, no exchange declarations, no consumer configuration. Ecotone handles everything.

### 2. Switch Message Brokers Instantly

Want to use a different message broker? Just change the channel builder:

| Message Broker | Configuration | Package |
|----------------|---------------|---------|
| **RabbitMQ** | `AmqpBackedMessageChannelBuilder::create('orders')` | `ecotone/amqp` |
| **Amazon SQS** | `SqsBackedMessageChannelBuilder::create('orders')` | `ecotone/sqs` |
| **Redis** | `RedisBackedMessageChannelBuilder::create('orders')` | `ecotone/redis` |
| **Kafka** | `KafkaMessageChannelBuilder::create('orders')` | `ecotone/kafka` |
| **Database** | `DbalBackedMessageChannelBuilder::create('orders')` | `ecotone/dbal` |

Your business logic remains **completely unchanged**. The `#[Asynchronous('orders')]` attribute works with any of these brokers.

### 3. Type-Safe Commands

Use proper PHP classes for your messages instead of raw arrays:

```php
class PlaceOrder
{
    public function __construct(
        public string $orderId,
        public string $product
    ) {}
}

// Send type-safe command
$ecotone->getCommandBus()->send(new PlaceOrder('123', 'Milk'));
```

Ecotone automatically serializes and deserializes your objects.

## How It Works Under the Hood

### Publishing Flow

1. **Send Command** → `CommandBus::send(new PlaceOrder(...))`
2. **Serialize** → Command is converted to JSON (or other format)
3. **Publish** → Message is sent to RabbitMQ queue named `orders`

### Consuming Flow

1. **Poll** → Consumer calls `$ecotone->run('orders')`
2. **Receive** → Message is fetched from RabbitMQ queue
3. **Deserialize** → JSON is converted back to `PlaceOrder` object
4. **Invoke Handler** → `OrderHandler::handle(PlaceOrder $command)` is called
5. **Acknowledge** → Message is removed from queue on success

### The Asynchronous Attribute

```php
#[Asynchronous('orders')]
#[CommandHandler(endpointId: 'orderHandler')]
public function handle(PlaceOrder $command): void
{
    // This runs in the consumer process, not the publisher
}
```

- `#[Asynchronous('orders')]` - Routes the command to the `orders` message channel
- `#[CommandHandler]` - Registers this method as a command handler
- `endpointId` - Unique identifier for this endpoint (required for async handlers)

### Message Channel Architecture

```
┌──────────────┐     ┌─────────────────┐     ┌──────────────┐
│  Publisher   │────▶│  Message Broker │────▶│   Consumer   │
│  (PHP CLI)   │     │   (RabbitMQ)    │     │  (PHP CLI)   │
└──────────────┘     └─────────────────┘     └──────────────┘
       │                                              │
       ▼                                              ▼
  CommandBus                                    CommandHandler
  .send(PlaceOrder)                            .handle(PlaceOrder)
```

## Try It Yourself

1. **Clone this repository** and run the example
2. **Check RabbitMQ Management UI** at `http://localhost:15672` (guest/guest)
3. **Modify the handler** to see how messages are processed
4. **Switch to a different broker** by changing one line

## Learn More

- 📚 [Ecotone Documentation](https://docs.ecotone.tech)

## Keywords

PHP Message Queue, RabbitMQ PHP, Kafka PHP, Amazon SQS PHP, Redis Pub/Sub PHP, Async PHP, PHP Message Broker, PHP Event-Driven Architecture, CQRS PHP, PHP Microservices, Ecotone Framework, PHP Messaging, Asynchronous PHP Processing, PHP Queue System, PHP Event Bus