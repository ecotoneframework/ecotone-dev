---
type: llm
---
PASS if `AuditLog::onPlaced` reads the headers with `#[Header('<name>')]` (or `#[Headers] array`), the always-present user id as a non-nullable parameter and the optional sales channel as `?string` or with a default (e.g. `= 'unknown'` or a null check that falls back to "unknown"). FAIL if the optional sales channel header is declared non-nullable without a default, or headers are read by some non-Ecotone mechanism.
