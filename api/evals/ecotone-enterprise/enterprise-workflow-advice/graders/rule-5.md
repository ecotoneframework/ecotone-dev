---
type: llm
---
PASS if the payment retry and failure routing is declared as an interface extending `Ecotone\Api\Gateway\CommandBus` carrying `#[InstantRetry(retryTimes: ..., exceptions: [DeadlockException::class])]` and `#[ErrorChannel('payment_failures')]`, injected where payment commands are sent. FAIL if `#[InstantRetry]` is placed on a `#[CommandHandler]` method, or the retry is implemented with a try/catch loop instead.
