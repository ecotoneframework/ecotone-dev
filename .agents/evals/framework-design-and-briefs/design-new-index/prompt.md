---
description: "Writing a design and a brief for a new lookup feature"
tags: [framework-design-and-briefs]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I want to let applications query the event store for 'all events tagged with customerId=X'. My idea: add a new `ecotone_customer_tag_index` table with a nightly backfill job. Write a short design note for this and a task brief I can hand to another agent to implement it. The change alters what `EventStore::load()` returns for tagged queries, which applications will notice. The implementation will also move three types (`TagQuery`, `TagFilter`, `TagIndex`) into `Ecotone\Api\EventSourcing`, one commit per type; most files import two of them. Tell me what the report should say about reverting that move. Then a reviewer gave me 6 numbered findings (R1-R6) on the design: say how I should handle that list. Keep it concrete.
