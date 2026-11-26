---
description: "Opening a pull request for a change to an Ecotone Pulse page and a message flow"
tags: [framework-pull-request]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev) on branch `feat/pulse-held-keys`. The failed messages page of Ecotone Pulse (apps/Pulse/templates and its controller) now lists the partition keys held in the dead letter, and in packages/Dbal a message of a held key now goes to the dead letter instead of its handler. Tests, checks and the reviews are all green and committed. Please take it from here: push and open the PR. Describe exactly what you'll do, step by step, including the PR body and any commands. You can't actually run anything right now.
