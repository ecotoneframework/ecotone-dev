---
description: "A CI check fails but passes locally"
tags: [framework-dev-environment]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). My PR's `Split Testing (Dbal, 8.2, mysql)` job failed on `test_consumer_stops_after_execution_limit`, but it passes on my machine. Also phpstan reports one new error in `packages/Dbal/src/Recoverability/DbalDeadLetterHandler.php` after I renamed a class. What do I do with each? Be concrete.
