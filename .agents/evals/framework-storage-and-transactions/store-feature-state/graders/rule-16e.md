---
type: llm
---
PASS if the store obtains its database connection from a connection factory at the moment it uses it (for example `CachedConnectionFactory::createContext()->getDbalConnection()` inside the method, or an equivalent per-call resolution), takes the factory and not a `Connection` as its dependency, and opens no connection of its own. FAIL if the store holds a `Doctrine\DBAL\Connection` or `DbalContext` in a property (constructor-injected or lazily cached), or builds one with `DriverManager::getConnection()`.
