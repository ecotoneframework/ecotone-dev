---
type: llm
---
PASS if the database connection is configured with `LaravelConnectionReference::defaultConnection('mysql')` returned from a `#[ServiceContext]` method, in a configuration class without constructor parameters. FAIL if the answer configures a DSN string, builds a Doctrine/DBAL connection by hand, or registers the connection in a service provider instead.
