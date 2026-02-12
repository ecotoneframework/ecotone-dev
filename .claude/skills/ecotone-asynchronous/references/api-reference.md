# Asynchronous Processing API Reference

## #[Scheduled] Attribute

```php
use Ecotone\Messaging\Attribute\Scheduled;

#[Scheduled(
    requestChannelName: 'channelName',  // Channel to send the return value to
    endpointId: 'myScheduler',          // Unique endpoint identifier
    requiredInterceptorNames: []        // Optional interceptor names
)]
```

The method's return value is sent as a message to `requestChannelName`.

## #[Poller] Attribute

```php
use Ecotone\Messaging\Attribute\Poller;

#[Poller(
    cron: '',                           // Cron expression (e.g. '*/5 * * * *')
    errorChannelName: '',               // Error channel for failures
    fixedRateInMilliseconds: 1000,      // Poll interval (default 1000ms)
    initialDelayInMilliseconds: 0,      // Delay before first execution
    memoryLimitInMegabytes: 0,          // Memory limit (0 = unlimited)
    handledMessageLimit: 0,             // Message limit (0 = unlimited)
    executionTimeLimitInMilliseconds: 0, // Time limit (0 = unlimited)
    fixedRateExpression: null,          // Runtime expression for fixed rate
    cronExpression: null                // Runtime expression for cron
)]
```

## #[Priority] Attribute

```php
use Ecotone\Messaging\Attribute\Endpoint\Priority;

#[Priority(10)]
```

- Sets `MessageHeaders::PRIORITY` header on the message
- Higher number = higher priority (processed first when multiple messages are queued)
- Can target `Attribute::TARGET_CLASS | Attribute::TARGET_METHOD`
- Default priority is `1`

## #[TimeToLive] Attribute

```php
use Ecotone\Messaging\Attribute\Endpoint\TimeToLive;
use Ecotone\Messaging\Scheduling\TimeSpan;

// Integer (milliseconds)
#[TimeToLive(60000)]

// TimeSpan object
#[TimeToLive(time: TimeSpan::withMinutes(5))]

// Expression-based
#[TimeToLive(expression: "reference('config').getTtl()")]
```

- Sets `MessageHeaders::TIME_TO_LIVE` header
- Message discarded if not consumed within TTL
- Can target `Attribute::TARGET_CLASS | Attribute::TARGET_METHOD`

## TimeSpan Factory Methods

```php
use Ecotone\Messaging\Scheduling\TimeSpan;

TimeSpan::withMilliseconds(500)
TimeSpan::withSeconds(30)
TimeSpan::withMinutes(5)
```

## PollingMetadata API

```php
use Ecotone\Messaging\Endpoint\PollingMetadata;

PollingMetadata::create('endpointId')
    ->setHandledMessageLimit(100)              // Stop after N messages
    ->setExecutionTimeLimitInMilliseconds(60000) // Stop after N ms
    ->setMemoryLimitInMegabytes(256)           // Stop at memory limit
    ->setFixedRateInMilliseconds(200)          // Poll interval
    ->setStopOnError(false)                    // Continue on error
    ->setFinishWhenNoMessages(false)           // Wait for messages
    ->setErrorChannelName('custom_error')      // Custom error channel
    ->setCron('*/5 * * * *');                  // Cron schedule
```

## DynamicMessageChannelBuilder Factory Methods

```php
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
```

| Method | Description |
|--------|-------------|
| `createRoundRobin(name, channelNames)` | Distributes messages across channels evenly |
| `createRoundRobinWithDifferentChannels(name, sendChannels, receiveChannels)` | Different channels for send/receive |
| `createWithHeaderBasedStrategy(name, headerName, headerMapping, ?defaultChannel)` | Routes based on message header value |
| `createThrottlingStrategy(name, requestChannelName, channelNames)` | Throttling-based consumption |
| `createNoStrategy(name)` | No-op channel for custom strategy attachment |

### Customization Methods

```php
$channel = DynamicMessageChannelBuilder::createRoundRobin('orders', ['ch1', 'ch2'])
    ->withCustomSendingStrategy('customSendChannel')
    ->withCustomReceivingStrategy('customReceiveChannel')
    ->withHeaderSendingStrategy('routeHeader', ['value1' => 'ch1'], 'defaultCh')
    ->withInternalChannels([...]);
```

## Channel Builder Types

### SimpleMessageChannelBuilder

```php
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

// Queue channel (pollable, for async processing)
SimpleMessageChannelBuilder::createQueueChannel('channel_name');

// Direct channel (point-to-point, synchronous)
SimpleMessageChannelBuilder::createDirectMessageChannel('channel_name');

// Publish-subscribe channel
SimpleMessageChannelBuilder::createPublishSubscribeChannel('channel_name');
```

### DbalBackedMessageChannelBuilder

```php
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;

// Basic DBAL channel
DbalBackedMessageChannelBuilder::create('orders');

// With custom connection reference
DbalBackedMessageChannelBuilder::create('orders', 'custom_connection');
```

### AmqpBackedMessageChannelBuilder

```php
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;

AmqpBackedMessageChannelBuilder::create('orders');
```

### SqsBackedMessageChannelBuilder

```php
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;

SqsBackedMessageChannelBuilder::create('orders');
```

### RedisBackedMessageChannelBuilder

```php
use Ecotone\Redis\RedisBackedMessageChannelBuilder;

RedisBackedMessageChannelBuilder::create('orders');
```

## Common Cron Expressions

| Expression | Meaning |
|-----------|---------|
| `*/5 * * * *` | Every 5 minutes |
| `0 * * * *` | Every hour |
| `0 8 * * *` | Daily at 8 AM |
| `0 0 * * 1` | Every Monday at midnight |
| `0 0 1 * *` | First day of month at midnight |
