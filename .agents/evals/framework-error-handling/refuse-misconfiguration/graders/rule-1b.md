---
type: llm
---
PASS if, for case (c), the context the runtime error needs (handler name, expression, endpoint) is prepared at container-build time and passed in (e.g. as a value object in the Definition), not looked up via reflection when the message fails. FAIL if the runtime path reflects or searches for the context at failure time. Also PASS if the answer explicitly states the failure context is built once at bootstrap.
