---
type: llm
---
PASS if the clock is pinned at bootstrap (TestConfiguration ... withTimeAt) and time is moved with advanceTimeBy(), with run() before advancing showing nothing handled and run() after showing it handled. FAIL if it uses sleep() or real time.
