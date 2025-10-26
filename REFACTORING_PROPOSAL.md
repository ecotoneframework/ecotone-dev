# Refactoring Proposal: Replace Timeout Parameter with PollingMetadata in MessagePoller

## Problem Statement

Currently, `AmqpStreamInboundChannelAdapter` has a fixed `commitInterval` that is set during construction. However, when a consumer is run with execution time limits or message limits via `PollingMetadata`, the adapter may stop before committing the current batch, leading to message reprocessing.

**Example scenario:**
- `commitInterval = 100` (commit every 100 messages)
- Consumer runs with `handledMessageLimit = 50` or `executionTimeLimitInMilliseconds = 5000`
- Consumer stops after 50 messages without committing, causing reprocessing on next run

## Solution Overview

Replace the `int $timeoutInMilliseconds` parameter with `PollingMetadata $pollingMetadata` in the `MessagePoller.receiveWithTimeout()` method. Since `PollingMetadata` already contains the timeout value, this eliminates redundancy and provides adapters with full polling context to dynamically adjust their behavior.

## Proposed Changes

### 1. Update MessagePoller Interface
**File:** `packages/Ecotone/src/Messaging/MessagePoller.php`

```php
interface MessagePoller
{
    /**
     * Receive with timeout using polling metadata
     *
     * @param PollingMetadata $pollingMetadata Contains timeout and execution constraints
     */
    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message;
}
```

**Rationale:**
- Eliminates redundant timeout parameter
- Provides complete polling context to adapters
- Allows adapters to access all constraints: `handledMessageLimit`, `executionTimeLimitInMilliseconds`, etc.
- Cleaner, more maintainable API

### 2. Update All MessagePoller Implementations

**Files to update:**
- `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/MessagePoller/PollableChannelPollerAdapter.php`
- `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/MessagePoller/InvocationPollerAdapter.php`
- `packages/Enqueue/src/EnqueueInboundChannelAdapter.php`
- `packages/Amqp/src/AmqpStreamInboundChannelAdapter.php`
- `packages/Kafka/src/Inbound/KafkaInboundChannelAdapter.php`

All implementations should update their signature to accept `PollingMetadata` instead of `int $timeoutInMilliseconds`.

**Example for PollableChannelPollerAdapter:**
```php
public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
{
    $timeoutInMilliseconds = $pollingMetadata->getFixedRateInMilliseconds();
    $message = $timeoutInMilliseconds
        ? $this->pollableChannel->receiveWithTimeout($timeoutInMilliseconds)
        : $this->pollableChannel->receive();

    if ($message) {
        $message = MessageBuilder::fromMessage($message)
            ->setHeader(MessageHeaders::POLLED_CHANNEL_NAME, $this->pollableChannelName)
            ->build();
    }
    return $message;
}
```

### 3. Update Call Sites

**Primary call site:** `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/PollToGatewayTaskExecutor.php`

```php
// Line 31: Change from
$message = $this->messagePoller->receiveWithTimeout($pollingMetadata->getExecutionTimeLimitInMilliseconds());

// To
$message = $this->messagePoller->receiveWithTimeout($pollingMetadata);
```

### 4. Update AmqpStreamInboundChannelAdapter

Override `commitInterval` when polling has execution constraints:

```php
public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
{
    // Override commit interval if polling has execution constraints
    if ($pollingMetadata->getHandledMessageLimit() > 0 || $pollingMetadata->getExecutionTimeLimitInMilliseconds() > 0) {
        $this->batchCommitCoordinator = new BatchCommitCoordinator(
            1, // Force commit interval to 1 to ensure all messages committed before consumer stops
            $this->positionTracker,
            $this->getConsumerId(),
        );
    }

    // Extract timeout from PollingMetadata
    $timeout = $pollingMetadata->getFixedRateInMilliseconds();

    // ... rest of implementation using $timeout
}
```

**Key benefit:** Now the adapter has full visibility into all polling constraints and can make intelligent decisions about commit intervals.

### 5. Add Test Cases

**File:** `packages/Amqp/tests/Integration/AmqpStreamChannelTest.php`

Add tests following existing patterns:
- `test_commit_interval_overridden_with_execution_time_limit()`
- `test_commit_interval_overridden_with_handled_message_limit()`

## Implementation Order

1. **Phase 1:** Update `MessagePoller` interface and implementations in `packages/Ecotone`
2. **Phase 2:** Update `packages/Enqueue/src/EnqueueInboundChannelAdapter.php`
3. **Phase 3:** Update `packages/Amqp/src/AmqpStreamInboundChannelAdapter.php`
4. **Phase 4:** Update `packages/Kafka/src/Inbound/KafkaInboundChannelAdapter.php`
5. **Phase 5:** Add test cases and run full test suite

## Backward Compatibility

**Note:** This is a breaking change to the `MessagePoller` interface, but:
- The interface is internal (not part of public API)
- All implementations are within Ecotone framework
- All call sites are within framework code
- No external code depends on this interface
- Change is localized to framework internals

## Files Affected

| Package | File | Change |
|---------|------|--------|
| Ecotone | MessagePoller.php | Interface update |
| Ecotone | PollableChannelPollerAdapter.php | Implementation |
| Ecotone | InvocationPollerAdapter.php | Implementation |
| Ecotone | PollToGatewayTaskExecutor.php | Call site |
| Enqueue | EnqueueInboundChannelAdapter.php | Implementation |
| Amqp | AmqpStreamInboundChannelAdapter.php | Implementation + logic |
| Amqp | AmqpStreamChannelTest.php | Tests |
| Kafka | KafkaInboundChannelAdapter.php | Implementation |

