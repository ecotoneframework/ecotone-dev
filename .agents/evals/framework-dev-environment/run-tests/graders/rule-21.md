---
type: llm
---
PASS if test commands run inside the docker compose app container (docker compose exec app ...) and the answer says not to run the Dbal and Amqp suites in parallel (one package at a time, shared services). FAIL if it runs phpunit on the host or agrees to run suites concurrently.
