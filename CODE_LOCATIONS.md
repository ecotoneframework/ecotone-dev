# Code Locations and Line Numbers

## Phase 1: Ecotone Package

### 1. MessagePoller Interface
**File:** `packages/Ecotone/src/Messaging/MessagePoller.php`
- **Lines:** 1-16 (entire file)
- **Change:** Update method signature on line 14
- **Current:** `public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message;`
- **New:** `public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message;`

### 2. PollableChannelPollerAdapter
**File:** `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/MessagePoller/PollableChannelPollerAdapter.php`
- **Lines:** 27-39 (receiveWithTimeout method)
- **Change:** Update method signature and extract timeout from metadata
- **Key:** Extract `$timeoutInMilliseconds = $pollingMetadata->getFixedRateInMilliseconds();`

### 3. InvocationPollerAdapter
**File:** `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/MessagePoller/InvocationPollerAdapter.php`
- **Lines:** 18-27 (receiveWithTimeout method)
- **Change:** Update method signature
- **Note:** No timeout extraction needed (invocation-based)

### 4. PollToGatewayTaskExecutor
**File:** `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/PollToGatewayTaskExecutor.php`
- **Lines:** 26-44 (execute method)
- **Change:** Line 31 - pass $pollingMetadata instead of timeout
- **Current:** `$this->messagePoller->receiveWithTimeout($pollingMetadata->getExecutionTimeLimitInMilliseconds());`
- **New:** `$this->messagePoller->receiveWithTimeout($pollingMetadata);`

---

## Phase 2: Enqueue Package

### 5. EnqueueInboundChannelAdapter
**File:** `packages/Enqueue/src/EnqueueInboundChannelAdapter.php`
- **Lines:** 41-62 (receiveWithTimeout method)
- **Change:** Update method signature and extract timeout
- **Key:** Extract `$timeoutInMilliseconds = $pollingMetadata->getFixedRateInMilliseconds() ?: $this->receiveTimeoutInMilliseconds;`
- **Line 59:** Use extracted timeout in `$consumer->receive($timeoutInMilliseconds)`

---

## Phase 3: AMQP Package

### 6. AmqpStreamInboundChannelAdapter
**File:** `packages/Amqp/src/AmqpStreamInboundChannelAdapter.php`
- **Lines:** 116-167 (receiveWithTimeout method)
- **Changes:**
  - Update method signature on line 116
  - Add commit interval override logic at start of method (after line 116)
  - Extract timeout: `$timeout = $pollingMetadata->getFixedRateInMilliseconds();`
  - Use $timeout instead of $timeout parameter throughout method

**Commit Interval Override Logic (add after line 116):**
```php
// Override commit interval if polling has execution constraints
if ($pollingMetadata->getHandledMessageLimit() > 0 || 
    $pollingMetadata->getExecutionTimeLimitInMilliseconds() > 0) {
    $this->batchCommitCoordinator = new BatchCommitCoordinator(
        1, // Force commit interval to 1
        $this->positionTracker,
        $this->getConsumerId(),
    );
}
```

### 7. AmqpStreamChannelTest
**File:** `packages/Amqp/tests/Integration/AmqpStreamChannelTest.php`
- **Add after line 913** (after `test_commit_interval_with_prefetch_count`)
- **Add Test 1:** `test_commit_interval_overridden_with_execution_time_limit()`
- **Add Test 2:** `test_commit_interval_overridden_with_handled_message_limit()`

**Test Pattern to Follow:**
- Lines 862-913: `test_commit_interval_with_prefetch_count()` - use as template
- Lines 915-950+: `test_commit_interval_with_prefetch_count_lower_than_commit_interval()` - use as template

---

## Phase 4: Kafka Package

### 8. KafkaInboundChannelAdapter
**File:** `packages/Kafka/src/Inbound/KafkaInboundChannelAdapter.php`
- **Lines:** 29-44 (receiveWithTimeout method)
- **Change:** Update method signature and extract timeout
- **Key:** Extract `$timeoutInMilliseconds = $pollingMetadata->getFixedRateInMilliseconds() ?: $this->receiveTimeoutInMilliseconds;`
- **Line 33:** Use extracted timeout in `$consumer->consume($timeoutInMilliseconds)`

---

## Import Statements to Add

### In files that use PollingMetadata:
```php
use Ecotone\Messaging\Endpoint\PollingMetadata;
```

**Files needing import:**
- PollableChannelPollerAdapter.php
- InvocationPollerAdapter.php
- EnqueueInboundChannelAdapter.php
- AmqpStreamInboundChannelAdapter.php
- KafkaInboundChannelAdapter.php

---

## Testing Locations

### Run Tests After Each Phase

**Phase 1 (Ecotone):**
```bash
cd packages/Ecotone
./vendor/bin/phpunit tests/Messaging/Unit/Endpoint/PollingConsumer/
./vendor/bin/phpunit tests/Messaging/Unit/Endpoint/Poller/
```

**Phase 2 (Enqueue):**
```bash
cd packages/Enqueue
./vendor/bin/phpunit tests/
```

**Phase 3 (AMQP):**
```bash
cd packages/Amqp
./vendor/bin/phpunit tests/Integration/AmqpStreamChannelTest.php
```

**Phase 4 (Kafka):**
```bash
cd packages/Kafka
./vendor/bin/phpunit tests/
```

---

## Summary of Changes

| Phase | Package | Files | Lines | Type |
|-------|---------|-------|-------|------|
| 1 | Ecotone | 4 | ~100 | Interface + implementations |
| 2 | Enqueue | 1 | ~20 | Implementation |
| 3 | Amqp | 2 | ~50 + tests | Implementation + tests |
| 4 | Kafka | 1 | ~20 | Implementation |
| **Total** | **4** | **8** | **~190** | **Code + tests** |

