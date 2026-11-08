---
type: llm
---
PASS if the `ShippingProcess` handler for `PaymentConfirmed` declares `identifierMapping: ['orderId' => 'payload.reference']` (key `orderId`, the saga's identifier) and `PaymentConfirmed` itself is left unchanged. FAIL if the answer modifies `PaymentConfirmed`, wraps it in a new event just to rename the property, uses a key that is not `orderId`, or uses an expression other than `payload.reference`.
