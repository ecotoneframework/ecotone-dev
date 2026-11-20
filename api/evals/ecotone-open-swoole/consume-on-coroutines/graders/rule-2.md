---
type: llm
---
PASS if the answer replaces `SymfonyConnectionReference::defaultManagerRegistry('default')` with a connection Ecotone can open per coroutine (`new DbalConnectionFactory(...)` / `Ecotone\Api\Dbal\DbalConnectionFactory`, or `SymfonyConnectionReference::defaultConnection(...)`/`createForConnection(...)`) AND sets the pool to at least 30 connections (`connection_pool_size` or `withConnectionPoolSize()`), or states that the pool must be at least as large as the number of coroutines. FAIL if the manager-registry connection is kept for the coroutine consumer, or the pool size is left at its default of 10 without comment while 30 coroutines are used.
