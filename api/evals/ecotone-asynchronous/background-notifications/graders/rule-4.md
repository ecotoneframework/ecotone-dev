---
type: llm
---
PASS if the answer tells the user to run the worker with `bin/console ecotone:run <channel>` (optionally with limits such as `--handledMessageLimit`) using the same channel name as `#[Asynchronous]`. FAIL if no consumer command is given, a different command (e.g. `messenger:consume`) is suggested for Ecotone's channel, or the channel name does not match.
