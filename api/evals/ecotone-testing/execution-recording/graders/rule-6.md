---
type: llm
---
PASS if the test boots with `EcotoneLite::bootstrapFlowTesting()` passing `TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)` (or the same with a span recorder only if one is otherwise needed), reads `$ecotone->popExecutionRecording()`, prints its `lines` or `linesWithEveryHint`, and asserts on `processes` (for example a process `title` naming `OrderService::place`). FAIL if it parses logs, collects spans from an `InMemorySpanRecorder` by hand to rebuild the steps, uses `getExecutionRecording()` or `withExecutionRecording()`, or reads Ecotone classes outside `Ecotone\Api`.
