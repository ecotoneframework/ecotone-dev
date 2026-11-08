---
description: "Starting a DBAL-backed feature: which framework skills apply and how names are verified"
tags: [framework-orientation]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I'm about to implement a new feature: a DBAL-backed "message receipt" store that records which command ids were handled, exposed to applications through a new gateway and a new `#[CommandHandler]`-level attribute option, with tests. Before writing any code, tell me which project guidance you load for this and in what order, and how you will make sure the class names and attribute namespaces you use (for example the CommandHandler attribute) are the current ones. Don't write the implementation.
