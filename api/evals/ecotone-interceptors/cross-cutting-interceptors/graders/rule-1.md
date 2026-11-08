---
type: llm
---
PASS if every `#[Around]` interceptor in the answer takes a `MethodInvocation`, calls `$invocation->proceed()` and returns its result (any cleanup or logging happens around that call, and exceptions are re-thrown, not swallowed). FAIL if any `#[Around]` method fails to call `proceed()`, discards its return value (e.g. declares `void` or returns nothing after calling it), or swallows the handler's exception.
