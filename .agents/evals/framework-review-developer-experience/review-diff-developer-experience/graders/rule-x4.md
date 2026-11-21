---
type: llm
---
PASS if the report decides the resilient-default requirement (DX7) missing, so that the review does not pass, because the default (noDlq = true) silently drops the message after the last attempt, and says the default must be the resilient one (keep the message: dead letter / error channel), with discarding as an explicit opt-in. FAIL if the message-losing default is not raised, or is raised only as a remark that does not stop the review from passing. The reviewer cannot open the repository or search it in this case: a report that says so, or that calls some decisions provisional for that reason, is judged on its content exactly as any other and still passes.
