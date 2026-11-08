---
type: llm
---
PASS if the audit log after command handlers is written from an `#[Around]` interceptor after `proceed()` (or another mechanism that runs for `void` handlers), or if the answer explicitly notes that `#[After]` would not run for these `void` handlers. FAIL if the audit log relies on an `#[After]` interceptor on `void` command handlers without acknowledging that it never fires for them.
