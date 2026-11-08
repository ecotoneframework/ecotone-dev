---
type: llm
---
PASS if the payment process is a `#[Saga]` class with an `#[Identifier]` property for the order id, created by a `public static` `#[EventHandler]` for `OrderWasPlaced` that returns `self`, and handling `PaymentFailed`, `PaymentSucceeded` and `ShipmentDelivered` with non-static `#[EventHandler]` methods. FAIL if the process is a plain service storing state in a property or its own table, has no `#[Identifier]`, or is started by an instance method.
