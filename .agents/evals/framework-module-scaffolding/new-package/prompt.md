---
description: "Scaffolding a new monorepo package with a module"
tags: [framework-module-scaffolding]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I want to add a new package `packages/Nats` (NATS message broker integration) with one module that registers NATS channels from a `NatsChannelConfiguration` returned by `#[ServiceContext]`. List the steps and files to create or modify so the package and its module are picked up, and describe how the module is tested. Don't ask questions.
