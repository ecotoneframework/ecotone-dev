---
type: llm
---
PASS if the role-check interceptor targets `RequiresRole` handlers through `pointcut: RequiresRole::class` or a parameter typed `RequiresRole` (receiving the attribute instance), and the timing interceptor targets `Timed` the same way. FAIL if either interceptor uses a broad pointcut such as `CommandHandler::class` and decides at runtime (e.g. via reflection) whether the handler carries the attribute.
