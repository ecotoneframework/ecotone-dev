---
type: llm
---
PASS if every dependency is a required constructor parameter. FAIL if any parameter has a default that constructs an object (e.g. `Clock $clock = new NativeClock()`) or the constructor falls back with `?? new ...`.
