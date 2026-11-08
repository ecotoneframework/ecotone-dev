---
type: llm
---
PASS if every handler attribute used in the answer (CommandHandler, EventHandler, QueryHandler, Asynchronous, Header, InternalHandler) is imported from the `Ecotone\Api\Attribute\` namespace. FAIL if any handler attribute is imported from `Ecotone\Modelling\Attribute\` or `Ecotone\Messaging\Attribute\`, if `#[ServiceActivator]` is used, or if handler attributes are used with no import at all.
