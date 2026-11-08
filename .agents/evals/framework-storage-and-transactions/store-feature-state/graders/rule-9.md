---
type: llm
---
PASS if an in-memory implementation is provided (named to mirror the DBAL one, e.g. InMemory...) and one shared test suite boots the application against both storages through the same gateway/behaviour. FAIL if there is no in-memory twin or the two are tested separately.
