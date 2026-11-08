---
type: llm
---
PASS if any eval case it proposes is a self-contained task prompt (code in the prompt, asking for the work) that does not quote the rule being graded. FAIL if its proposed eval prompt names the rule or tells the agent the expected answer. PASS also if no eval prompt text is proposed but the approach to testing is self-contained tasks.
