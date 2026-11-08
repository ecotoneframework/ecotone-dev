---
description: "Adding a public configuration object, a console option and a header"
tags: [framework-public-api]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev), package `packages/Dbal`. I'm adding a feature applications configure: a `DeadLetterRetentionConfiguration` with a retention period in days and a flag to keep replayed messages, which users return from a `#[ServiceContext]` method. I also add a console command `ecotone:deadletter:purge` with an option for the retention days, and the framework stamps each dead-lettered message with a header holding the purge deadline. Write the configuration class (with its file path and namespace), the console command method signature with its attribute, and the header name constant. Don't ask questions.
