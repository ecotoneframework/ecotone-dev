---
type: llm
---
PASS if the synchronous `ReserveStock` retry is configured on the bus: either `#[InstantRetry(retryTimes: 2, exceptions: [DeadlockDetected::class])]` on an interface extending `CommandBus` that the controller injects and sends through (noted as an Ecotone Enterprise feature), or `InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 2, [DeadlockDetected::class])` returned from a `#[ServiceContext]` method. FAIL if `#[InstantRetry]` or `#[ErrorChannel]` is placed on the `PaymentService` class or a handler method, or the retry is a hand-written loop in the controller or handler.
