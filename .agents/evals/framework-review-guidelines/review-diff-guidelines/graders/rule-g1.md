---
type: llm
---
PASS if the report names both changed files (the dead letter handler and the new test) and states the purpose of the change, before or alongside its findings. The change is given as a diff without line numbers and the reviewer cannot open the files: a report that says so and judges from the diff still passes, and a file, class, method or quoted line counts as a location. FAIL only if a changed file or the purpose is missing.
