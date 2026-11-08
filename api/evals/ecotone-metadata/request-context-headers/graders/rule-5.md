---
type: llm
---
PASS if the test uses `EcotoneLite::bootstrapFlowTesting()`, sends `PlaceOrder` with `metadata: ['userId' => ...]` (e.g. via `$ecotone->sendCommand(..., metadata: [...])`), and asserts the header on the published event with `$ecotone->popRecordedEventHeaders()[0]->get('userId')` or by observing it in the `AuditLog` handler. FAIL if the test never sends the header as metadata, asserts nothing about the event's header, or inspects Ecotone internals (reflection, private services).
