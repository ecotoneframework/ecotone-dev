---
type: llm
---
PASS if all three statements below are true of the answer; FAIL if any is false. Check each one separately, anywhere
in the answer, in any wording.

1. It states that the sending console command must run inside a Swoole or OpenSwoole coroutine.
2. It states that the held messages are released only when the outer console command returns successfully.
3. It states that a long-running command holds its messages until then.

Also FAIL if the answer:
- says notifications start after an inner bus call returns;
- suggests registering another channel in place of the coroutine message channel so that console commands can send
  without a coroutine.

Offering a different design for notifications that must start during the command (a shorter command, sending the
work to a channel consumed with `ecotone:run`, a durable channel for work that must survive) is not a FAIL, as long
as the three statements hold.
