---
type: llm
---
PASS if `Order` uses the `WithEvents` trait (`Ecotone\Api\Modelling\WithEvents`) and records `OrderWasCancelled` with `$this->recordThat(...)` inside the cancel handler. FAIL if the aggregate takes or calls an `EventBus`, if the event is published from a controller or service after the command, or if `OrderWasCancelled` is not emitted at all.
