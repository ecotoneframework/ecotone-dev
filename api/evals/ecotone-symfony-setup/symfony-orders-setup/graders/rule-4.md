---
type: llm
---
PASS if the database connection is configured with `SymfonyConnectionReference::defaultManagerRegistry('default')` returned from a `#[ServiceContext]` method. FAIL if the answer configures a DSN string for Ecotone, builds a DBAL connection by hand, or registers a connection service in `services.yaml` instead.
