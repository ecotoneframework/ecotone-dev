---
description: "Recovering a lost optimistic write in a framework store"
tags: [framework-storage-and-transactions]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm adding a DBAL-backed reservation store to the Ecotone framework. An asynchronous handler reads a reservation
row and writes it with `UPDATE reservations SET version = version + 1 WHERE id = ? AND version = ?`. Another
consumer may save the row first, so the update affects zero rows. The handler runs under Ecotone's default DBAL
transaction configuration. Show how the store and handler should recover and describe the focused tests, including
what happens if contention continues through all attempts. Name the exception and the configuration an application
can use for a hot reservation key. Don't ask questions.
