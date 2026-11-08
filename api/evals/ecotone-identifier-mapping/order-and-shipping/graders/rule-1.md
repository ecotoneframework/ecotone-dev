---
type: llm
---
PASS if `CancelOrder` has a property named `orderId` and neither the class nor its `Order` handler adds `#[TargetIdentifier]`, `identifierMapping`, `identifierMetadataMapping` or `aggregate.id` metadata for it. FAIL if `CancelOrder` uses a different property name, or if any mapping or attribute is added that name matching already makes unnecessary.
