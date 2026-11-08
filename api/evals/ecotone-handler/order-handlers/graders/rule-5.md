---
type: llm
---
PASS if the controller passes the user id as metadata on the bus call (e.g. `send(new PlaceOrder(...), metadata: ['userId' => $id])`) and the e-mail handler reads it through a `#[Header('userId')]` parameter. FAIL if `PlaceOrder` gains a user id property, if the handler reads the session, the request or a global, or if the e-mail handler obtains the user id by any means other than a `#[Header]` parameter.
