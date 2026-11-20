---
type: llm
---
PASS if the answer says that PostgreSQL cannot be used inside OpenSwoole coroutines as is (Ecotone refuses it / the query blocks the whole process) and names at least one way out: moving to MySQL or MariaDB, using Swoole 5.1+ with `SWOOLE_HOOK_PDO_PGSQL`, or handling one message at a time. FAIL if the answer keeps PostgreSQL with `--coroutines` on OpenSwoole and presents it as working.
