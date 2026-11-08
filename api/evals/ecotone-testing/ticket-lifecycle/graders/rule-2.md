---
type: llm
---
PASS if the survey test calls `run('notifications')` (the channel named in `#[Asynchronous]`) before asserting that the email was sent. FAIL if it expects the email to be sent right after the command without running the channel, or runs a different channel name.
