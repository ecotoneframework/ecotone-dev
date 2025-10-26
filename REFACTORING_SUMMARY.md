# Refactoring Summary: MessagePoller Interface Update

## Overview

This refactoring replaces the `int $timeoutInMilliseconds` parameter with `PollingMetadata $pollingMetadata` in the `MessagePoller.receiveWithTimeout()` method. This provides adapters with complete polling context to make intelligent decisions about message handling.

## Key Benefits

1. **Eliminates Redundancy** - Timeout is already in PollingMetadata
2. **Provides Full Context** - Adapters can access all constraints (message limits, time limits, etc.)
3. **Enables Smart Behavior** - AmqpStreamInboundChannelAdapter can override commitInterval based on constraints
4. **Cleaner API** - Single parameter instead of multiple values
5. **Prevents Message Reprocessing** - Ensures commits happen before consumer stops

## The Problem It Solves

**Scenario:** Consumer with `commitInterval=100` but `handledMessageLimit=50`
- Consumer processes 50 messages
- Consumer stops (message limit reached)
- Last batch not committed (only 50 messages, not 100)
- Next run reprocesses all 50 messages

**Solution:** Override `commitInterval=1` when execution constraints are set
- Consumer processes 50 messages
- Each message committed immediately
- Consumer stops
- Next run starts from correct position

## Interface Change

### Before
```php
interface MessagePoller
{
    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message;
}
```

### After
```php
interface MessagePoller
{
    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message;
}
```

## Implementation Changes

### 1. PollableChannelPollerAdapter
Extract timeout from PollingMetadata:
```php
$timeoutInMilliseconds = $pollingMetadata->getFixedRateInMilliseconds();
```

### 2. InvocationPollerAdapter
Accept PollingMetadata (no timeout needed for invocation-based polling)

### 3. EnqueueInboundChannelAdapter
Extract timeout from PollingMetadata for consumer.receive()

### 4. AmqpStreamInboundChannelAdapter (KEY CHANGE)
Override commitInterval when constraints detected:
```php
if ($pollingMetadata->getHandledMessageLimit() > 0 || 
    $pollingMetadata->getExecutionTimeLimitInMilliseconds() > 0) {
    // Force commitInterval = 1
}
```

### 5. KafkaInboundChannelAdapter
Extract timeout from PollingMetadata

## Call Site Changes

**PollToGatewayTaskExecutor.php (line 31)**

Before:
```php
$message = $this->messagePoller->receiveWithTimeout(
    $pollingMetadata->getExecutionTimeLimitInMilliseconds()
);
```

After:
```php
$message = $this->messagePoller->receiveWithTimeout($pollingMetadata);
```

## Files Modified

| File | Type | Changes |
|------|------|---------|
| MessagePoller.php | Interface | Signature change |
| PollableChannelPollerAdapter.php | Implementation | Extract timeout from metadata |
| InvocationPollerAdapter.php | Implementation | Accept metadata parameter |
| PollToGatewayTaskExecutor.php | Call site | Pass metadata instead of timeout |
| EnqueueInboundChannelAdapter.php | Implementation | Extract timeout from metadata |
| AmqpStreamInboundChannelAdapter.php | Implementation | Override commitInterval logic |
| KafkaInboundChannelAdapter.php | Implementation | Extract timeout from metadata |
| AmqpStreamChannelTest.php | Tests | Add 2 new test cases |

## Test Cases to Add

1. `test_commit_interval_overridden_with_execution_time_limit()`
   - Setup: commitInterval=100, executionTimeLimitInMilliseconds=1000
   - Verify: All messages committed before timeout
   - Verify: No reprocessing on next run

2. `test_commit_interval_overridden_with_handled_message_limit()`
   - Setup: commitInterval=100, handledMessageLimit=50
   - Verify: All 50 messages committed
   - Verify: No reprocessing on next run

## Implementation Phases

1. **Phase 1 (Ecotone)** - Interface + 2 implementations
2. **Phase 2 (Enqueue)** - Base adapter
3. **Phase 3 (AMQP)** - Stream adapter + logic
4. **Phase 4 (Kafka)** - Kafka adapter
5. **Phase 5 (Testing)** - Add tests + verify all pass

## Scope

- **Internal Change** - MessagePoller is internal interface
- **No Public API Impact** - Not exposed to end users
- **Framework-Only** - All implementations within framework
- **Localized** - Changes contained to polling infrastructure

