---
type: llm
---
PASS if the delay is tested with the test clock: time is pinned (e.g. `TestConfiguration::createWithDefaults()->withTimeAt(...)` or `changeTimeTo()`), the channel is run before the delay elapses with no email sent, then `advanceTimeBy(TimeSpan::withMinutes(30))` (or an equivalent later `changeTimeTo()`) followed by `run()` shows the email sent. FAIL if the test uses `sleep()`/`usleep()`, relies on real time passing, or never checks that nothing is sent before 30 minutes.
