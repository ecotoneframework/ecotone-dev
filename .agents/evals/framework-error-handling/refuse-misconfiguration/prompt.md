---
description: "Handling an unsupported attribute placement and a duplicate configuration"
tags: [framework-error-handling]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I'm adding a new method-level attribute `#[Deduplicated(expression: '...')]` for command handlers. Users might (a) put it on a class instead of a method, (b) register two `DeduplicationConfiguration` objects via `#[ServiceContext]`, (c) put it on a handler whose expression references a header that does not exist, which we can only know when a message arrives. For each case tell me where in the framework the problem is detected and when (bootstrap or runtime), show the exception message text you would throw, and the test you'd write for it. Don't ask questions.
