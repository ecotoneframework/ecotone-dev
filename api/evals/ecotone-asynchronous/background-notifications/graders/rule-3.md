---
type: llm
---
PASS if the 24-hour reminder is an `#[Asynchronous]` handler delayed with `#[Delayed(...)]` (e.g. `#[Delayed(hours: 24)]` or 86400000 ms) or sent with `deliveryDelay` metadata. FAIL if the delay uses `sleep()`/`usleep()`, a cron job or scheduler polling for due orders, or a hand-written "send after" timestamp check instead of Ecotone's delayed delivery.
