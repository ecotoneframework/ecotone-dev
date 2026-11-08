---
description: "Turning repeated review feedback into enforcement"
tags: [framework-agent-tooling]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). Agents keep making two mistakes in PRs: (1) test classes in packages/*/tests declare test methods in camelCase like `testItWorks` instead of snake_case; (2) when a feature needs an exception, they write vague messages like 'Invalid configuration'. I want to stop both. What exactly do you change in the repo for each, and how do you verify your change works? Don't ask questions; be specific about files.
