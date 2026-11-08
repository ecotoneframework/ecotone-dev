---
description: "Adding an Enterprise variant of an open-core service, and a request to port an Enterprise file elsewhere"
tags: [framework-enterprise-licensing]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). Two things: (1) The open-source `RetryRunner` in packages/Ecotone/src retries a failed handler. I want an Enterprise-only variant that adds instant retries with jitter for licensed users. Describe the files, their licence headers, and how the right one is chosen at runtime. (2) Separately, there's a bug in `packages/Ecotone/src/Projecting/PartitionedProjectionRunner.php`, which carries a `licence Enterprise` header: an off-by-one in the batch size. Can you fix it here? And after that, can you copy that class into my company's internal framework repo so we have the same thing there?
