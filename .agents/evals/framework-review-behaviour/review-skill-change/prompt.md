---
description: "Behaviour review of a branch that changes a skill"
tags: [framework-review-behaviour]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the feature/behaviour reviewer for a branch in the Ecotone framework monorepo (ecotone-dev), before the PR is opened. Problem: agents kept writing camelCase test method names. The branch changes `.agents/skills/framework-testing/SKILL.md` (rewording rule 11 to say test methods are snake_case) and nothing else. CI on the branch is green. The author says: 'CI passed, so the skill change is verified.' Describe exactly how you review this change and what your report contains. You can't run commands right now; say what you would run and how you'd rank the outcomes.
