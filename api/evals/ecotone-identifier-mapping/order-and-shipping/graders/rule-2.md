---
type: llm
---
PASS if `ChangeShippingAddress` keeps the property `id` and marks it `#[TargetIdentifier('orderId')]` (imported from `Ecotone\Api\Attribute`). FAIL if the property is renamed against the stated constraint, if the mapping is done only on the handler with `identifierMapping` instead of on our own class, or if the id is resolved manually in the handler.
