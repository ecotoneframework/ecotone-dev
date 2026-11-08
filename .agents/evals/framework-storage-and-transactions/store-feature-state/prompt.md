---
description: "Designing a storage-backed feature: locking, in-memory twin, tables and transactions"
tags: [framework-storage-and-transactions]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I'm building a 'command receipt' feature in packages/Dbal: after a command handler succeeds we record the command id so a redelivered command is skipped. Two consumers can handle the same command at once. Outline the implementation: the table and how it gets created, where in the flow the receipt is written relative to the handler's transaction, how two concurrent consumers are prevented from both recording it, what happens when the table is missing, how this works in EcotoneLite tests without a database, and which databases you verify on. Be concrete; don't ask questions.
