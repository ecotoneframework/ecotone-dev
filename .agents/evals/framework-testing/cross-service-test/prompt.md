---
description: "Testing a request that waits for a reply from another service"
tags: [framework-testing]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev), in apps/Pulse. The Orders service sends `order.awaitInvoice` and waits up to 10 seconds (on Ecotone's clock) for the Billing service to reply over a shared channel `billing`. I need a PHPUnit test proving the wait ends when Billing answers 6 seconds later, and the measured wait is 6 seconds. My first idea was to fork a process that runs Billing's consumer in a loop and measure microtime() around the send. Write the test (full code). Don't ask questions.
