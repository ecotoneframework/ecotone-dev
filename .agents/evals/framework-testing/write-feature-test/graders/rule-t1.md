---
type: llm
---
The case runs without a repository, so the answer cannot run anything; grade the order it states. PASS if the stated order writes the failing test first and runs it red against the base commit before any change to the fix, then makes it pass. FAIL if it writes or describes the fix before the test, leaves out running the test red before the fix, or runs the test for the first time only after the fix.
