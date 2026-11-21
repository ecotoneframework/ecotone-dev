---
type: llm
---
PASS if the answer says the sending console command must execute inside a Swoole/OpenSwoole coroutine and releases held messages only when that outer console command returns successfully; a long-running command holds them until then. FAIL if it says notifications start after an inner bus call returns, suggests replacing the production channel for console commands, or omits the coroutine requirement or the outer command completion boundary.
