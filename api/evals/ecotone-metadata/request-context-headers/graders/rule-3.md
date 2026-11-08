---
type: llm
---
PASS if the answer relies on Ecotone propagating the command's headers to `OrderWasPlaced` automatically: `OrderService::place` publishes the event without copying `userId`/sales channel/`requestId` into the event or into the publish metadata by hand, and the answer states that the headers propagate. FAIL if the command handler reads the headers just to re-pass them to `$eventBus->publish(..., metadata: ...)`, or copies them into the event payload, presenting that as necessary.
