---
type: llm
---
PASS if both services are booted with EcotoneLite (or the framework kernel) in the one test process, Billing is consumed only via run(), the clock is pinned at bootstrap and the 6-second delay is produced on the test clock (e.g. FlowTestSupport::whenWaiting() running billing's run() once the pinned clock passes +6 seconds), and the wait is asserted from $ecotone->now(). FAIL if the test uses pcntl_fork, proc_open/Process, sockets/pipes, sleep/usleep, or a microtime()/time() loop or wall-clock assertion.
