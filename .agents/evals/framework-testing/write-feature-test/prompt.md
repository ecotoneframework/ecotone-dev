---
description: "Writing the tests for an async, time-dependent framework feature"
tags: [framework-testing]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I'm fixing a bug: an event handler marked `#[Asynchronous('notifications')]` with a 5-minute delay runs immediately on the DBAL channel. The fix is in the DBAL outbound adapter. The delay must hold whether the event is published through the EventBus, through an aggregate's recorded events, or through a custom business interface gateway. Write the PHPUnit test file for packages/Dbal/tests (full code) and say in what order you write the test vs the fix. Don't ask questions.
