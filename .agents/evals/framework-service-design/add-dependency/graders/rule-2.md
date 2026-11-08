---
type: llm
---
PASS if no constructor parameter is nullable (no ?LoggerInterface, no LoggerInterface|null, no = null default on a service); an optional logger is solved by always injecting one (e.g. a NullLogger registered in the container) or choosing between services in the container. FAIL if any service dependency is nullable or defaults to null.
