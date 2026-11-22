---
description: "Making a new routing feature show in the pull request's flow comparison"
tags: [framework-testing]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm in the Ecotone framework monorepo (ecotone-dev). I added a feature: an event handler can now declare
`#[EventHandler('order.placed', onlyWhenHeader: 'priority')]`, and Ecotone routes the event to it only when the
message carries that header. The unit tests in packages/Ecotone/tests pass. The pull request's flow comparison
comment shows no change at all for my branch, and the reviewer asks why. Explain why, and write out everything
else the change needs (full code for any file you add, and which files you leave alone) so the comparison shows
the new behaviour. Don't ask questions.
