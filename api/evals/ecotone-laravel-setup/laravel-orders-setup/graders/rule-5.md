---
type: llm
---
PASS if `App\Models\Order` extends Eloquent `Model`, is marked `#[Aggregate]`, has an `#[IdentifierMethod('id')]` getter, uses `WithEvents` to record `OrderWasPlaced`, and the `cancel()` action handler sets the status without calling `$this->save()`. FAIL if `cancel()` calls `$this->save()`, the identifier is declared with `#[Identifier]` on a property, or a custom repository is written for the model.
