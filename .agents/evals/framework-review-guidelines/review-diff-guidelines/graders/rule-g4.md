---
type: llm
---
PASS if the requirements decided missing are about what this diff adds or changes (the new parameter, property, comment, log call and test, including consequences of them such as the handler's wiring not being updated for the new parameter), and anything about code the diff did not touch is omitted or marked pre-existing. FAIL only if the report decides a requirement missing because of a defect of untouched, pre-existing code without marking it pre-existing. The reviewer cannot open the repository or search it in this case: a report that says so, or that calls some decisions provisional for that reason, is judged on its content exactly as any other and still passes.
