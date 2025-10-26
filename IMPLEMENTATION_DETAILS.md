# Implementation Details: Before/After Code Changes

## 1. MessagePoller Interface

**File:** `packages/Ecotone/src/Messaging/MessagePoller.php`

### Before
```php
interface MessagePoller
{
    /**
     * Receive with timeout
     * Tries to receive message till time out passes
     */
    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message;
}
```

### After
```php
interface MessagePoller
{
    /**
     * Receive with timeout using polling metadata
     * Tries to receive message till time out passes
     * 
     * @param PollingMetadata $pollingMetadata Contains timeout and execution constraints
     */
    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message;
}
```

---

## 2. PollableChannelPollerAdapter

**File:** `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/MessagePoller/PollableChannelPollerAdapter.php`

### Before
```php
public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
{
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

### After
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

---

## 3. InvocationPollerAdapter

**File:** `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/MessagePoller/InvocationPollerAdapter.php`

### Before
```php
public function receiveWithTimeout(int $timeoutInMilliseconds = 0): ?Message
{
    $result = $this->serviceToCall->{$this->methodName}();
    if ($result === null) {
        return null;
    }
    return $result instanceof Message
        ? $result
        : MessageBuilder::withPayload($result)->build();
}
```

### After
```php
public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
{
    $result = $this->serviceToCall->{$this->methodName}();
    if ($result === null) {
        return null;
    }
    return $result instanceof Message
        ? $result
        : MessageBuilder::withPayload($result)->build();
}
```

---

## 4. PollToGatewayTaskExecutor

**File:** `packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/PollToGatewayTaskExecutor.php`

### Before
```php
public function execute(PollingMetadata $pollingMetadata): void
{
    try {
        $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::ENABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT);

        $message = $this->messagePoller->receiveWithTimeout($pollingMetadata->getExecutionTimeLimitInMilliseconds());
    } finally {
        $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::DISABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT);
    }
    // ...
}
```

### After
```php
public function execute(PollingMetadata $pollingMetadata): void
{
    try {
        $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::ENABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT);

        $message = $this->messagePoller->receiveWithTimeout($pollingMetadata);
    } finally {
        $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::DISABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT);
    }
    // ...
}
```

---

## 5. AmqpStreamInboundChannelAdapter (KEY CHANGE)

**File:** `packages/Amqp/src/AmqpStreamInboundChannelAdapter.php`

### Before
```php
public function receiveWithTimeout(int $timeout = 0): ?Message
{
    try {
        if ($message = $this->queueChannel->receive()) {
            // ... handle message
        }
        // ... rest of implementation
    } catch (AMQPException|AMQPIOException $exception) {
        // ... error handling
    }
}
```

### After
```php
public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
{
    // Override commit interval if polling has execution constraints
    if ($pollingMetadata->getHandledMessageLimit() > 0 || 
        $pollingMetadata->getExecutionTimeLimitInMilliseconds() > 0) {
        $this->batchCommitCoordinator = new BatchCommitCoordinator(
            1, // Force commit interval to 1
            $this->positionTracker,
            $this->getConsumerId(),
        );
    }

    $timeout = $pollingMetadata->getFixedRateInMilliseconds();
    
    try {
        if ($message = $this->queueChannel->receive()) {
            // ... handle message
        }
        // ... rest of implementation using $timeout
    } catch (AMQPException|AMQPIOException $exception) {
        // ... error handling
    }
}
```

---

## 6. EnqueueInboundChannelAdapter

**File:** `packages/Enqueue/src/EnqueueInboundChannelAdapter.php`

### Before
```php
public function receiveWithTimeout(int $timeoutInMilliseconds = 0): ?Message
{
    // ... setup code
    $message = $consumer->receive($timeoutInMilliseconds ?: $this->receiveTimeoutInMilliseconds);
    // ...
}
```

### After
```php
public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
{
    // ... setup code
    $timeoutInMilliseconds = $pollingMetadata->getFixedRateInMilliseconds() ?: $this->receiveTimeoutInMilliseconds;
    $message = $consumer->receive($timeoutInMilliseconds);
    // ...
}
```

---

## 7. KafkaInboundChannelAdapter

**File:** `packages/Kafka/src/Inbound/KafkaInboundChannelAdapter.php`

### Before
```php
public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
{
    $consumer = $this->kafkaAdmin->getConsumer($this->endpointId);
    $message = $consumer->consume($timeoutInMilliseconds ?: $this->receiveTimeoutInMilliseconds);
    // ...
}
```

### After
```php
public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
{
    $consumer = $this->kafkaAdmin->getConsumer($this->endpointId);
    $timeoutInMilliseconds = $pollingMetadata->getFixedRateInMilliseconds() ?: $this->receiveTimeoutInMilliseconds;
    $message = $consumer->consume($timeoutInMilliseconds);
    // ...
}
```

