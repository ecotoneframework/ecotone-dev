---
type: llm
---
PASS if the query methods declare `OrderView` (or `?OrderView`, or `array` documented as `@return OrderView[]`) as their return type and the answer provides a class with a `#[Converter]` method converting an `array` row to `OrderView`. FAIL if the methods return raw arrays that the caller then maps by hand, or no `#[Converter]` is provided.
