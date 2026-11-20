---
type: llm
---
PASS if the nested parent ids are kept in a state object obtained through `ExecutionScope::stateOf($this, ...)` (Ecotone\Messaging\Execution\ExecutionScope) each time they are read or written, the interceptor itself has no mutable or static property holding them, and the answer proves it with a test that interleaves two executions using fibers (`Fiber::suspend()` inside a handler) rather than only sequential sends. FAIL if the ids live in an instance property such as `private array $parents = []`, in a static property, in a property that caches the state object, or if no interleaving test is described.
