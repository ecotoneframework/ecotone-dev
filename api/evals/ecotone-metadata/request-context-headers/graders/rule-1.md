---
type: llm
---
PASS if the controller passes the user id (and the sales channel when present) as metadata on the bus call, e.g. `$this->commandBus->send(new PlaceOrder(...), metadata: ['userId' => ...])`, and neither `PlaceOrder` nor `OrderWasPlaced` gains a user id property. FAIL if the user id is added to a command or event class, or is passed through some other channel such as a global/static variable.
