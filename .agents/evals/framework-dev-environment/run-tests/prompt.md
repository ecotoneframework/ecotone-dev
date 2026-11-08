---
description: "Running one test, a package suite and code style"
tags: [framework-dev-environment]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I changed `packages/Dbal/src/DbalOutboundChannelAdapter.php` and the test `test_it_sends_with_delay` in `packages/Dbal/tests`. Give me the exact shell commands, in order, to: (1) run just that one test, (2) run the whole Dbal package suite, (3) fix code style for my change. Also: can I run the Dbal and Amqp suites at the same time to save time? Answer with commands, not questions.
