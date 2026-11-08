---
type: llm
---
PASS if the saga asks for payment by returning a `TakePayment` from an event handler declared with `outputChannelName: 'takePayment'`, and the retry handler for `PaymentFailed` has a nullable return type and returns `null` once the retry was already used (after marking the process failed). FAIL if the saga injects and calls the `CommandBus` to send `TakePayment`, if `outputChannelName` names something other than `takePayment`, or if the retry limit is enforced by throwing instead of returning `null`.
