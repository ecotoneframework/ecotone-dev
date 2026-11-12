---
type: llm
---
PASS if the report flags, as a blocking or top-severity finding, that the default (noDlq = true) silently drops the message after the last attempt, and says the default must be the resilient one (keep the message: dead letter / error channel), with discarding as an explicit opt-in. FAIL if the message-losing default is not raised or is rated a nit.
