---
type: llm
---
PASS if the answer starts the consumer with `ecotone:run notifications` and a `--coroutines` option (for example `--coroutines=30`), or configures `PollingMetadata::create('notifications')->withCoroutines(...)`, and does not write its own script or loop that calls `Coroutine::create()`, `go()` or `Coroutine::run()` around the consumer. FAIL if the consumer is started without coroutines, or the answer hand-writes the coroutine loop.
