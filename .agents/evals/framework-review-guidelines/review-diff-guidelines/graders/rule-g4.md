---
type: llm
---
PASS if the findings are about what this diff adds or changes (the new parameter, property, comment, log call and test, including consequences of them such as the handler's wiring not being updated for the new parameter), and anything about code the diff did not touch is omitted or marked pre-existing. FAIL only if the report files a defect of untouched, pre-existing code as a new blocking finding without marking it pre-existing.
