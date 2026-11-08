---
type: llm
---
PASS if `requestId` is added to every command by a `#[Before]` or `#[Presend]` interceptor (pointcut e.g. `CommandHandler::class` or the `CommandBus`) with `changeHeaders: true` that returns an array containing `requestId`. FAIL if `requestId` is added at each call site, by an `#[Around]` interceptor, or by an interceptor without `changeHeaders: true`.
